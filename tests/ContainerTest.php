<?php

declare(strict_types=1);

namespace Rammewerk\Component\Container\Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use Rammewerk\Component\Container\Container;
use Rammewerk\Component\Container\Error\ContainerException;
use Rammewerk\Component\Container\PsrContainer;
use Rammewerk\Component\Container\Tests\TestData\TestClassA;
use Rammewerk\Component\Container\Tests\TestData\TestClassB;
use Rammewerk\Component\Container\Tests\TestData\TestClassC;
use Rammewerk\Component\Container\Tests\TestData\TestClassD;
use Rammewerk\Component\Container\Tests\TestData\TestClassE;
use Rammewerk\Component\Container\Tests\TestData\TestClassEInterface;
use Rammewerk\Component\Container\Tests\TestData\TestClassF;
use ReflectionClass;
use Throwable;
use TypeError;

class ContainerTest extends TestCase {

    private Container $container;



    public function setUp(): void {
        $this->container = new Container();
    }



    /** Check if a class can be created by container */
    public function testCreatingClassThroughContainer(): void {
        $classA = $this->container->create(TestClassA::class);
        $this->assertInstanceOf(TestClassA::class, $classA);
    }



    /** Make sure invalid class throws correct exception */
    public function testInvalidClass(): void {
        try {
            /** @phpstan-ignore-next-line */
            $this->container->create('invalid_class');
        } catch (Throwable $e) {
            $this->assertInstanceOf(ContainerException::class, $e);
            return;
        }
        $this->fail("The code reached an unexpected point.");
    }



    /** Test a class that requires another class as dependency. Container should auto-resolve dependency */
    public function testAutoResolveDependency(): void {
        $classB = $this->container->create(TestClassB::class);
        $this->assertInstanceOf(TestClassB::class, $classB);
    }



    /**
     * Test to check that container fails if it tries to create class with a string parameter.
     * This cannot be auto-resolved.
     */
    public function testInvalidParameter(): void {
        try {
            $this->container->create(TestClassC::class)->value;
        } catch (Throwable $e) {
            $this->assertInstanceOf(TypeError::class, $e);
            return;
        }
        $this->fail("The code reached an unexpected point.");
    }



    /**
     * Test class that require a string parameter and that it pass this from creation
     */
    public function testStringParameter(): void {
        $value = 'string';
        $class = $this->container->create(TestClassC::class, [$value]);
        $this->assertSame($value, $class->value);
    }



    /**
     * Test binding of interface. Test class that requires a dependency of interface and that we can bind this
     */
    public function testBindClass(): void {
        $container = $this->container->bind(TestClassEInterface::class, TestClassE::class);
        $classD = $container->create(TestClassD::class);
        $this->assertTrue($classD->getE());
    }



    /**
     * Test bindings of interface. Test a class that require an interface.
     */
    public function testBindings(): void {
        $container = $this->container->bindings([TestClassEInterface::class => TestClassE::class]);
        $classD = $container->create(TestClassD::class);
        $this->assertTrue($classD->getE());
    }



    /**
     * Test error when trying to create an interface class
     */
    public function testUnboundInterfaceClass(): void {
        try {
            $this->container->create(TestClassEInterface::class);
        } catch (Throwable $e) {
            $this->assertInstanceOf(ContainerException::class, $e);
            return;
        }
        $this->fail("The code reached an unexpected point.");
    }



    /**
     * Test shared class and also a reset of previous cache on shared
     */
    public function testSharedClassAndCaching(): void {
        // Create an instance
        $classC = $this->container->create(TestClassC::class, ['ignore']);
        // Check that value is the same as we have created
        $this->assertEquals('ignore', $classC->value);

        // Get properties from container
        $reflection = new ReflectionClass($this->container);
        $property_cache = $reflection->getProperty('cache');
        $property_shared = $reflection->getProperty('shared');
        $property_instances = $reflection->getProperty('instances');

        /**
         * Check that class is cached and not in shared instances
         *
         * @var array<class-string, Closure> $cache
         */
        $cache = $property_cache->getValue($this->container);
        /** @var array<class-string, object> $instances */
        $instances = $property_instances->getValue($this->container);
        $this->assertArrayHasKey(TestClassC::class, $cache);
        $this->assertArrayNotHasKey(TestClassC::class, $instances);

        // Define class as shared
        $container = $this->container->share([TestClassC::class]);

        /**
         * Check that class is set to share
         *
         * @var class-string[] $shared
         */
        $shared = $property_shared->getValue($container);
        $this->assertArrayHasKey(TestClassC::class, $shared);

        $same_cache = $property_cache->getValue($container);
        $this->assertArrayNotHasKey(TestClassC::class, $same_cache);

        // Now calling the class again (this makes it singleton)
        $container->create(TestClassC::class, ['ignore']);

        /**
         * Check that cache is removed
         */
        $new_instances = $property_instances->getValue($container);
        $new_cache = $property_cache->getValue($container);
        $this->assertArrayHasKey(TestClassC::class, $new_instances);
        $this->assertArrayNotHasKey(TestClassC::class, $new_cache);

        $classShared = $container->create(TestClassC::class, ['correct']);

        /**
         * Class should now have instances
         *
         * @var array<class-string, object> $created_instances
         */
        $created_instances = $property_instances->getValue($container);
        $this->assertArrayHasKey(TestClassC::class, $created_instances);

        /**
         * Same class created should now be the same as the shared instance
         */
        $classDuplicate = $container->create(TestClassC::class);
        $this->assertSame($classDuplicate, $classShared);
        $this->assertEquals($classShared->value, $classDuplicate->value);

    }



    public function testConstructorVariable(): void {
        $container = $this->container->bind(TestClassEInterface::class, function () {
            $classA = new TestClassA();
            $classB = new TestClassB($classA);
            return new TestClassF($classB, 'test');
        });
        $class = $container->create(TestClassEInterface::class);
        $this->assertTrue($class->get());
    }



    public function testConstructorVariableEmpty(): void {
        $container = $this->container->bind(
            TestClassEInterface::class,
            function (Container $container): TestClassEInterface {
                return $container->create(TestClassF::class, ['']);
            },
        );
        $class = $container->create(TestClassEInterface::class);
        $this->assertFalse($class->get());
    }



    public function testSharedBinding(): void {
        $container = $this->container->share([TestClassEInterface::class]);
        $container = $container->bind(TestClassEInterface::class, TestClassE::class);
        $class_1 = $container->create(TestClassEInterface::class);
        $class_2 = $container->create(TestClassEInterface::class);
        $this->assertSame($class_1, $class_2);
    }



    public function testSharedBindingClosure(): void {
        $container = $this->container->share([TestClassEInterface::class]);
        $container = $container->bind(TestClassEInterface::class, function (Container $container) {
            return $container->create(TestClassF::class, ['test']);
        });
        $class_1 = $container->create(TestClassEInterface::class);
        $class_2 = $container->create(TestClassEInterface::class);
        $this->assertSame($class_1, $class_2);
    }



    public function testNotSameInstance(): void {
        $instance_1 = $this->container->create(TestClassC::class, ['instance_1']);
        $instance_2 = $this->container->create(TestClassC::class, ['instance_2']);
        $this->assertSame('instance_1', $instance_1->value);
        $this->assertSame('instance_2', $instance_2->value);
        $this->assertNotSame($instance_1->value, $instance_2->value);
    }



    public function testPsrContainer(): void {
        $container = new PsrContainer();
        $class = $container->get(TestClassA::class);
        $this->assertInstanceOf(TestClassA::class, $class);
        $this->assertSame(true, $container->has(TestClassA::class));
    }



    public function testBindingOfInstance(): void {
        $container = $this->container->bind(TestClassEInterface::class, new TestClassE());
        $class = $container->create(TestClassEInterface::class);
        $this->assertInstanceOf(TestClassE::class, $class);
    }


}