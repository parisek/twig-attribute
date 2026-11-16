#!/usr/bin/env bash
# Squash-merges a Dependabot pull request that cannot reach the users of the
# package: dev-only Composer or npm updates and GitHub Actions pins, minor or
# patch only, after every check is green. Every other case prints
# "SKIP: <reason>" and leaves the pull request to the owner.
# Adapted from portadesign-mcp's scripts/agent/dependabot-merge.sh.
#
# Usage: dependabot-merge.sh [<head branch> <head sha>]
#   With no arguments it checks every open Dependabot pull request.
#   DRY_RUN=1 never merges.
# Needs `gh`, `jq`, GH_TOKEN and GITHUB_REPOSITORY. Settings (environment):
#   REQUIRED_WORKFLOWS  comma-separated workflow names that must have passed
#   SKIP_BRANCHES       regex: Dependabot branches left to the owner (optional)
#   SKIP_DEPENDENCIES   space-separated package names left to the owner (optional)
#   MERGE_TOKEN_SET=1   GH_TOKEN may merge a change of a workflow file
set -euo pipefail

repo=${GITHUB_REPOSITORY:?"GITHUB_REPOSITORY is not set"}
required=${REQUIRED_WORKFLOWS:?"REQUIRED_WORKFLOWS is not set"}
bot='dependabot[bot]'
self_check='dependabot-merge'

api() { gh api "$@"; }
raw() { gh api -H 'Accept: application/vnd.github.raw' "repos/$repo/contents/$1?ref=$2"; }

# check <branch> <sha>: prints one line; merges when every rule holds.
check() (
	branch=$1
	sha=$2
	skip() { echo "SKIP: $*"; exit 0; }

	case "$branch" in
		dependabot/npm_and_yarn/*) kind=npm ;;
		dependabot/github_actions/*) kind=actions ;;
		dependabot/composer/*) kind=composer ;;
		*) skip "branch $branch is not a Dependabot branch of a known ecosystem" ;;
	esac
	if [ -n "${SKIP_BRANCHES:-}" ] && grep -qE "$SKIP_BRANCHES" <<<"$branch"; then
		skip "$branch matches SKIP_BRANCHES"
	fi

	prs=$(api "repos/$repo/pulls?state=open&base=main&head=${repo%%/*}:$branch&per_page=100")
	[ "$(jq 'length' <<<"$prs")" = 1 ] || skip "expected one open pull request for $branch into main"
	pr=$(jq '.[0]' <<<"$prs")
	number=$(jq -r '.number' <<<"$pr")
	title=$(jq -r '.title' <<<"$pr")

	jq -e --arg bot "$bot" '.user.login == $bot and .user.type == "Bot"' <<<"$pr" >/dev/null \
		|| skip "#$number was not opened by Dependabot"
	jq -e '.draft == false and .state == "open" and .base.ref == "main"' <<<"$pr" >/dev/null \
		|| skip "#$number is a draft, is closed or does not target main"
	jq -e --arg repo "$repo" '.head.repo.full_name == $repo' <<<"$pr" >/dev/null \
		|| skip "#$number comes from another repository"
	jq -e --arg sha "$sha" '.head.sha == $sha' <<<"$pr" >/dev/null \
		|| skip "#$number moved on from the tested commit $sha"

	commits=$(api "repos/$repo/pulls/$number/commits?per_page=100")
	jq -e --arg bot "$bot" 'length > 0 and length < 100
		and all(.[]; .author.login == $bot and .committer.login == "web-flow"
			and .commit.verification.verified == true)' <<<"$commits" >/dev/null \
		|| skip "#$number has a commit that is not a signed Dependabot commit"

	# Dependabot writes one `update-type:` and one `dependency-name:` line per update.
	message=$(jq -r '.[-1].commit.message' <<<"$commits")
	types=$(sed -n 's/^[[:space:]]*update-type:[[:space:]]*//p' <<<"$message" | tr -d '\r')
	[ -n "$types" ] || skip "#$number has no update-type metadata"
	other=$(grep -vxE 'version-update:semver-(patch|minor)' <<<"$types" || true)
	[ -z "$other" ] || skip "#$number has a major or unknown update: $(tr '\n' ' ' <<<"$other")"
	for dep in ${SKIP_DEPENDENCIES:-}; do
		if sed -n 's/^[[:space:]]*-[[:space:]]*dependency-name:[[:space:]]*//p' <<<"$message" | tr -d '\r"'"'"'' | grep -qxF "$dep"; then
			skip "#$number updates $dep, which SKIP_DEPENDENCIES leaves to the owner"
		fi
	done

	runs=$(api "repos/$repo/commits/$sha/check-runs?per_page=100")
	jq -e --arg self "$self_check" '.check_runs | length < 100
		and all(.[] | select(.name != $self); .status == "completed"
			and (.conclusion == "success" or .conclusion == "skipped" or .conclusion == "neutral"))' \
		<<<"$runs" >/dev/null || skip "#$number has a check run that is not finished and green"
	wruns=$(api "repos/$repo/actions/runs?head_sha=$sha&per_page=100")
	IFS=',' read -ra names <<<"$required"
	for name in "${names[@]}"; do
		jq -e --arg n "$name" '[.workflow_runs[] | select(.name == $n)] | length > 0
			and all(.[]; .status == "completed" and .conclusion == "success")' <<<"$wruns" >/dev/null \
			|| skip "#$number has no finished, successful run of the workflow \"$name\""
	done

	files=$(api "repos/$repo/pulls/$number/files?per_page=100")
	jq -e 'length > 0 and length < 100 and all(.[]; .status == "modified")' <<<"$files" >/dev/null \
		|| skip "#$number adds, removes or renames a file"
	only() { jq -e --arg re "$1" 'all(.[]; .filename | test($re))' <<<"$files" >/dev/null; }

	# unchanged <file> <jq filter>: the file is the same on the merge base and the head, outside the filter.
	base=
	unchanged() {
		if [ -z "$base" ]; then
			base=$(api "repos/$repo/compare/main...$sha" | jq -r '.merge_base_commit.sha')
			if [ -z "$base" ] || [ "$base" = null ]; then skip "#$number has no merge base with main"; fi
		fi
		local old new
		# A failed request stops the run here; it must not look like "nothing changed".
		old=$(raw "$1" "$base")
		new=$(raw "$1" "$sha")
		old=$(jq -S "$2" <<<"$old")
		new=$(jq -S "$2" <<<"$new")
		[ -n "$old" ] && [ "$old" = "$new" ]
	}

	case "$kind" in
		npm)
			only '^([^/]+/)*package(-lock)?\.json$' || skip "#$number changes a file other than package.json and package-lock.json"
			# The lockfile does not reach the users of a package; package.json outside devDependencies does.
			for f in $(jq -r '.[].filename | select(endswith("/package.json") or . == "package.json")' <<<"$files"); do
				unchanged "$f" 'del(.devDependencies)' || skip "#$number changes $f outside devDependencies"
			done
			;;
		actions)
			only '^\.github/workflows/[^/]+\.ya?ml$' || skip "#$number changes a file outside .github/workflows"
			use='^[+-][[:space:]]*(-[[:space:]]*)?uses:[[:space:]]*[^@[:space:]]+@[0-9a-f]{40}[[:space:]]+#[[:space:]]*v?[0-9]+(\.[0-9]+)*[[:space:]]*$'
			jq -e 'all(.[]; .patch != null)' <<<"$files" >/dev/null || skip "#$number has a change GitHub does not show as a patch"
			changed=$(jq -r '.[].patch' <<<"$files" | grep -E '^[+-]' || true)
			bad=$(grep -vE "$use" <<<"$changed" || true)
			[ -z "$bad" ] || skip "#$number changes a workflow line that is not a pinned uses: line"
			# One removed pin for each added pin of the same action: no step comes or goes.
			action() { grep -E "^[$1]" <<<"$changed" | sed -E 's/^.[[:space:]]*(-[[:space:]]*)?uses:[[:space:]]*([^@]+)@.*$/\2/' | sort; }
			if [ -z "$(action +)" ] || [ "$(action +)" != "$(action -)" ]; then
				skip "#$number does not keep the same set of actions"
			fi
			[ -n "${MERGE_TOKEN_SET:-}" ] || skip "#$number changes a workflow: it needs the DEPENDABOT_MERGE_TOKEN secret, or the owner's merge"
			;;
		composer)
			only '^composer\.(json|lock)$' || skip "#$number changes a file other than composer.json and composer.lock"
			unchanged composer.json 'del(.["require-dev"])' || skip "#$number changes composer.json outside require-dev"
			if only '^composer\.json$'; then :; else
				unchanged composer.lock 'del(.["packages-dev"], .["content-hash"], .["platform-dev"], .["plugin-api-version"])' \
					|| skip "#$number changes a package that users install"
			fi
			;;
	esac

	if [ "${DRY_RUN:-0}" = 1 ]; then
		echo "DRY RUN: would merge #$number ($kind): $title"
		exit 0
	fi

	echo "Merging #$number ($kind): $title"
	api -X PUT "repos/$repo/pulls/$number/merge" \
		-f merge_method=squash -f sha="$sha" \
		-f commit_title="$title (#$number)" \
		-f commit_message="Merged by dependabot-merge.yml: a dev-only minor or patch update with green checks." >/dev/null \
		|| { echo "FAIL: GitHub refused to merge #$number"; exit 1; }
)

if [ $# -ge 2 ]; then
	check "$1" "$2"
	exit
fi

status=0
while IFS=$'\t' read -r branch sha; do
	[ -n "$branch" ] || continue
	check "$branch" "$sha" || status=1
done < <(api "repos/$repo/pulls?state=open&base=main&per_page=100" \
	| jq -r --arg bot "$bot" '.[] | select(.user.login == $bot) | [.head.ref, .head.sha] | @tsv')
exit "$status"
