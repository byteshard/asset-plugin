# Contribution

## Commits

We are using semantic versioning for this repository. Prefix your commits depending on your change. For more information refer to <a href="https://juhani.gitlab.io/go-semrel-gitlab/commit-message/" target="_blank">https://juhani.gitlab.io/go-semrel-gitlab/commit-message/</a>

## Coding Style

byteShard follows the [PSR-12](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-12-extended-coding-style-guide.md) coding standard and the [PSR-4](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-4-autoloader.md) autoloading standard.

### PHPDoc

Every file needs a file-level phpdoc. Below is a valid format of the file-level block. Optional attributes are `internal` and `deprecated`:
```
/**
 * @copyright  Copyright (c) 2009 Bespin Studios GmbH
 * @license    See LICENSE file in the root of this repository
 * [@internal]
 * [@deprecated reason]
 */
```

Below is an example of a valid documentation block:

    /**
     * Do bar with foos
     *
     * @param  array<Foo> $foo
     * @return void
     *
     * @throws \Exception
     */
    public function bar(array $foo): void
    {
        // ...
    }

When the `@param` and `@return` attributes are redundant due to the use of native types, they can be removed:

    /**
     * Do foo with bar
     */
    public function bar(Foo $foo): void
    {
        //
    }

Due to strong typing, if the type is generic like an array, please specify the generic type through the use of the `@param` or `@return` attributes. For arrays use the array<Type> notation, using Type[] is discouraged:

    /**
     * Get foo from bar
     *
     * @return array<int, Foo>
     */
    public function getFoo(): array
    {
        return [
            Foo::getInstance()
        ];
    }

## Code quality

byteShard uses phpstan to statically analyze code before runtime. This increases code quality and helps to find bugs in an early stage. Use `./vendor/bin/phpstan analyze --memory-limit 1024M` to test your changes before creating a merge request.