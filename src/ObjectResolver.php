<?php

namespace Notes;

class ObjectResolver {

    const KEY_ENTITY_NAME = 01;
    const KEY_COLUMN_NAME = 02;

    public string $objectClass;

    public array $uses;

    public array $class;

    public array $properties;

    public array $methods;

    public function __construct(string $objectClass, bool $fullUses = true, bool $fullObject = true, $fullMethod = true, $fullProperty = true)
    {
        $this->objectClass = $objectClass;

        list($this->uses, $this->class, $this->methods, $this->properties) = array_values(
            ObjectReflection::fromClass($objectClass)->read($fullUses, $fullObject, $fullMethod, $fullProperty)
        );

        $this->resolveAnnotations();
    }

    /**
     * Transform an annotation into it's object's counterpart
     */
    public function getAnnotationFromClassname(string $className) : ? object
    {
        if ( $name = $this->uses[$className] ?? false) {
            foreach($this->class['tags'] as $item) {
                if ( $item['tag'] === $name ) {
                    return $this->instanciateAnnotationObject($item);
                }

                foreach($this->properties as $property) {
                    foreach($property['tags'] as $item) {
                        if ( $item['tag'] === $name ) {
                            return $this->instanciateAnnotationObject($item);
                        }
                    }
                }

                foreach($this->methods as $method) {
                    foreach($method['tags'] as $item) {
                        if ( $item['tag'] === $name ) {
                            return $this->instanciateAnnotationObject($item);
                        }
                    }
                }
            }

            throw new \TypeError("Annotation `$className` could not be found within your object `{$this->objectClass}`");
        }
        else {
            throw new \InvalidArgumentException("Class `$className` was not found within {$this->objectClass} uses statement (or it's children / traits)");
        }

        return null;
    }
    /**
     * Transform an annotation into it's object's counterpart
     */
    public function getAnnotationListFromClassname(string $className, bool $throwOnError = true) : array
    {
        $list = [];

        if ( $name = $this->uses[$className] ?? false) {
            foreach($this->class['tags'] as $item) {
                if ( $item['tag'] === $name ) {
                    $list[] = $this->instanciateAnnotationObject($item);
                }

                foreach($this->properties as $property) {
                    foreach($property['tags'] as $item) {
                        if ( $item['tag'] === $name ) {
                            $list[$property['name']] = $this->instanciateAnnotationObject($item);
                        }
                    }
                }

                foreach($this->methods as $method) {
                    foreach($method['tags'] as $item) {
                        if ( $item['tag'] === $name ) {
                            $list[$method['name']] = $this->instanciateAnnotationObject($item);
                        }
                    }
                }
            }
        }
        else {
            if ($throwOnError) throw new \InvalidArgumentException("Class `$className` was not found within {$this->objectClass} uses statement (or it's children / traits)");
        }

        return $list;
    }

    public function instanciateAnnotationObject(array $tagDefinition) : Annotation
    {
        $arguments = $this->extractArguments($tagDefinition['arguments']);

        if ( false === $class = array_search($tagDefinition['tag'], $this->uses) ) {
            throw new \InvalidArgumentException("Annotation class `{$tagDefinition['tag']}` was not found within {$this->objectClass} uses statement (or it's children / traits)");
        }

        $obj = new $class(... $arguments['constructor']);

        foreach($arguments['setter'] as $key => $value) {
            $obj->$key = $value;
        }

        return $obj;
    }

    /**
     * Extracts arguments from an Annotation definition, easing object's declaration.
     */
    protected function extractArguments(array $arguments) : array
    {
        $list = [
            'setter' => [],
            'constructor' => [],
        ];

        ksort($arguments);

        foreach($arguments as $key => $value) {
            $list[ is_int($key) ? 'constructor' : 'setter' ][$key] = $value;
        }

        return $list;
    }

    protected function resolveAnnotations()
    {
        foreach($this->class['tags'] as $key => &$tag) {
            $tag['object'] = $this->instanciateAnnotationObject($tag);
        }

        foreach($this->properties as &$property) {
            foreach($property['tags'] as &$tag){
                $tag['object'] = $this->instanciateAnnotationObject($tag);
            }
        }

        foreach($this->methods as &$method) {
            foreach($method['tags'] as &$tag){
                $tag['object'] = $this->instanciateAnnotationObject($tag);
            }
        }
    }
}
