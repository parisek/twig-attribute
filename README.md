Twig Attribute Extension
=======================
Twig is desperately missing wrapper function to handle HTML Attributes. I borrowed great [Attribute](https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21Template%21Attribute.php/class/Attribute/9.2.x) class from Drupal. It collects, sanitizes, and renders HTML attributes in a nice way.

This package was created based on issue [#2664570 - Move Attribute classes under Drupal\Component](https://www.drupal.org/project/drupal/issues/2664570) which created groundwork for using this class outside Drupal world. Unfortunately I had to copy code out because issue is still open. I hope issue will be merged soon, so I can switch to official component split off from Drupal core with proper attribution. Expected component should be available at [https://github.com/drupal/core-attribute](https://github.com/drupal/core-attribute)

## Installation

Twig Attribute Extension can be easily installed using [composer](http://getcomposer.org/)

    composer require parisek/twig-attribute

## Usage

```php
$twig = new Twig_Environment($loader);
$twig->addExtension(new Parisek\Twig\AttributeExtension());
```

To use in a symfony project [register the extensions as a service](http://symfony.com/doc/current/cookbook/templating/twig_extension.html#register-an-extension-as-a-service).

```yaml
services:
  twig.extension.attribute:
    class: Parisek\Twig\AttributeExtension
    tags:
      - { name: twig.extension }
```

## Template

```twig
{% set my_attribute = create_attribute() %}
{%
  set my_classes = [
    'kittens',
    'llamas',
    isKitten ? 'cats' : 'dogs',
  ]
%}
<div{{ my_attribute.addClass(my_classes).setAttribute('id', 'myUniqueId') }}>
  {{ content }}
</div>
```

```twig
<div{{ create_attribute({'class': ['region', 'region--header']}) }}>
  {{ content }}
</div>
```

Examples were copied from [official Drupal documentation](https://www.drupal.org/docs/8/theming-drupal-8/using-attributes-in-templates).

## Use Cases
- [Drupal - Pattern Lab](https://patternlab.io/)  
- [Wordpress - Timber](https://wordpress.org/plugins/timber-library/)
- [Pimcore - Templates](https://pimcore.com/en)

