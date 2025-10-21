# Contributing to Twig Attribute Extension

Thank you for your interest in contributing to the Twig Attribute Extension!

## Development Setup

1. Clone the repository:
   ```bash
   git clone https://github.com/parisek/twig-attribute.git
   cd twig-attribute
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

## Code Quality

We maintain high code quality standards. Before submitting a pull request:

### Run Tests
```bash
composer test
```

### Check Coding Standards
```bash
composer phpcs
```

### Run Static Analysis
```bash
composer phpstan
```

## Coding Standards

- Follow PSR-12 coding standards
- Add type declarations to all methods
- Include PHPDoc blocks for all public methods
- Write unit tests for new features
- Ensure all tests pass before submitting

## Pull Request Process

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. Run all quality checks (tests, phpcs, phpstan)
5. Commit your changes (`git commit -m 'Add amazing feature'`)
6. Push to the branch (`git push origin feature/amazing-feature`)
7. Open a Pull Request

## Reporting Issues

Please use the GitHub issue tracker to report bugs or suggest features.

Include:
- PHP version
- Twig version
- Steps to reproduce
- Expected vs actual behavior

## License

By contributing, you agree that your contributions will be licensed under the GPL-2.0-or-later license.
