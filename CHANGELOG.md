CHANGELOG
=========

1.2.5
---

- Added support for binding a concrete instance `bind(string $abstract, new ConcreteInstance())`

1.2.4
---

- Final improvements to performance for constructor parameters.

1.2.3
---

- Improved performance assigning shared and bindings.
- Improved performance resolving constructor parameters.
- Better caching mechanism for constructor parameters.

1.2.2
---

- Container will call class without arguments if empty constructor is defined.
- Better performance

1.2.1
---
Removed optional lazy container, now always lazy In benchmark tests, we see no reason to not use lazy container. It only
improves performance when using the container. No side effects has been found.

1.2.0
---

* Added support for PSR-11 ContainerInterface
* Added support for union types
* Added support for intersection types
* Refactored code to improve readability and maintainability
* Increased performance
* A more descriptive README

1.1.0
---

* Now has lazy loading of classes using PHP8.4 native lazyProxy. This is now default way to handle classes.
* New and better ReadMe Doc

1.0.0
---
Release ready for production:

* Optimized exported archive
* Added better DocBlock comments for better IDE support
* Tested with PHP 8.4.2
* New unit tests
* Added option to mark interfaces as shared instances.
* Fixed missing Closure support for bindings.

0.2.0
---

- A small improvement in performant when registering shared instances and bindings.
- Added PHP Unit test to make sure everything container behave as expected

0.1.0
---
Beta release