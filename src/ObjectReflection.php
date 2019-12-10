<?php

namespace Notes;

use Reflector, ReflectionClass, ReflectionProperty, ReflectionMethod;

class ObjectReflection {

    protected string $classname;

    public AnnotationReader $annotationReader;

    public function __construct($class, AnnotationReader $annotationReader = null) {
        $this->classname = ltrim($class, '\\');
        $this->classReflection = $class instanceof ReflectionClass ? $class : new ReflectionClass($class);
        $this->annotationReader = $annotationReader ?: AnnotationReader::fromClass($class);
    }

    public static function fromClass($class) : self
    {
        return new static($class);
    }

    public function read(bool $fullUses = true, bool $fullObject = true, $fullMethod = true, $fullProperty = true) : array
    {
        return [
            'uses'     => $this->gatherUses($fullUses),
            'class'    => $this->gatherClass($fullObject),
            'method'   => $this->gatherMethods($fullMethod),
            'property' => $this->gatherProperties($fullProperty),
        ];
    }

    public function gatherUses(bool $full = true) : array
    {
        if ( $full ) {
            if ( $parentClass = $this->classReflection->getParentClass() ) {
                $list = static::fromClass($parentClass)->gatherUses(true);
            }

            foreach($this->classReflection->getTraits() as $trait) {
                $list = array_replace(static::fromClass($trait)->gatherUses(true), $list ?? []);
            }
        }

        return array_replace($list ?? [], $this->getUsesStatements());
    }

    public function gatherClass(bool $full = true) : array
    {
        if ( $full ) {
            if ( $parentClass = $this->classReflection->getParentClass() ) {
                $class = static::fromClass($parentClass)->gatherClass(true);
            }

            if ( $traits = $this->classReflection->getTraits() ) {
                foreach($traits as $key => $value) {
                    $traitTags = static::fromClass($key)->gatherClass(true);
                }
            }

            if ( $interfaces = $this->classReflection->getInterfaces() ) {
                foreach($interfaces as $key => $value) {
                    $interfaceTags = static::fromClass($key)->gatherClass(true);
                }
            }

            $itemName = function($item) {
                return $item->getName();
            };
        }

        return array_merge_recursive($class ?? [], $traitTags ?? [], $interfaceTags ?? [], [
            'tags' => $this->annotationReader->getClass($this->classReflection)
        ] + ( ! $full ? [] : [
            'traits' => array_map($itemName, $traits),
            'interfaces' => array_map($itemName, $interfaces),
        ] ));
    }

    public function gatherProperties(bool $full = true, int $filter =
        ReflectionProperty::IS_PUBLIC    |
        ReflectionProperty::IS_PROTECTED |
        ReflectionProperty::IS_PRIVATE
    ) : array
    {
        $defaultValues = $this->classReflection->getDefaultProperties();

        if ( $full ) {
            if ( $parentClass = $this->classReflection->getParentClass() ) {
                $properties = static::fromClass($parentClass)->gatherProperties($full, $filter);
            }
        }

        $list = [];

        foreach($this->classReflection->getProperties($filter) as $property) {
            $current = [
                'name' => $property->getName()
            ];

            # Default value can be 'null', so isset() it not suitable here
            if ( array_key_exists($current['name'], $defaultValues) ) {
                $current['value'] = $defaultValues[ $current['name'] ];
            }

            if ( $property->hasType() ) {
                $current['type'] = $property->getType()->getName();
                $current['nullable'] = $property->getType()->allowsNull();
            }

            $current['tags'] = $this->annotationReader->getProperty($property);

            if ( $this->ignoreElementAnnotation($current['tags']) ) {
                continue;
            }

            $list[ $current['name'] ] = $current;
        }

        return array_merge($properties ?? [], $list);
    }

    public function gatherMethods(bool $full = true, int $filter =
        ReflectionMethod::IS_PUBLIC    |
        ReflectionMethod::IS_PROTECTED |
        ReflectionMethod::IS_PRIVATE   |
        ReflectionMethod::IS_STATIC
    ) : array
    {
        $list = [];

        if ( $full ) {
            if ( $parentClass = $this->classReflection->getParentClass() ) {
                $methods = static::fromClass($parentClass)->gatherMethods($full, $filter);
            }
        }

        foreach($this->classReflection->getMethods($filter) as $method) {
            if ( ! $full && ( $method->class !== $this->classname ) ) {
                continue;
            }

            $parameters = [];

            foreach($method->getParameters() as $parameter) {
                $parameters[$parameter->getName()] = [
                    'null' => $parameter->allowsNull(),
                    'position' => $parameter->getPosition(),
                    'type' => $parameter->hasType() ? $parameter->getType()->getName() : false,
                    'array' => $parameter->isArray(),
                    'callable' => $parameter->isCallable(),
                    'optional' => $parameter->isOptional(),
                    'byReference' => $parameter->isPassedByReference(),
                ];
            }

            $current = [
                'name' => $method->getName(),
                'type' => $method->hasReturnType() ? $method->getReturnType()->getName() : false,
                'constructor' => $method->isConstructor(),
                'destructor' => $method->isDestructor(),
                'parameters' => $parameters,
            ];

            $current['tags'] = $this->annotationReader->getMethod($method);

            if ( $this->ignoreElementAnnotation($current['tags']) ) {
                continue;
            }

            $list[ $current['name'] ] = $current;
        }

        return array_merge($methods ?? [], $list);
    }

    protected function ignoreElementAnnotation($tags) : bool
    {
        return in_array('IGNORE', array_map('strtoupper', array_column($tags, 'tag') ));
    }


    protected function readCode() : string
    {
        static $code = [];
        $fileName = $this->classReflection->getFilename();
        return $code[$fileName] ?? $code[$fileName] = file_get_contents($fileName);
    }

    protected function getUsesStatements() : array
    {
        $uses = [];
        $tokens = token_get_all( $c = $this->readCode() );

        while ( $token = array_shift($tokens) ) {

            if ( is_array($token) ) {
                list($token, $value) = $token;
            }

            switch ($token) {
                case T_CLASS:
                case T_TRAIT:
                case T_INTERFACE:
                    break 2;

    			case T_USE:
                    $isUse = true;
				break;

                case T_NS_SEPARATOR:
                    $isNamespace = $isUse;
                break;

                case T_STRING:
                    if ( $isNamespace && $latestString ) {
                        $statement[] = $latestString;
                    }

                    $latestString = $value;
                break;

                case T_AS:
                    # My\Name\Space\aClassHere `as` ClassAlias;
                    $replacedClass = implode("\\", array_merge($statement, [ $latestString ]));
                    $latestString = null;
                break;

                case T_WHITESPACE:
                case T_COMMENT:
                case T_DOC_COMMENT:
                break;

                case '{':
                    # opening a sub-namespace -> \My\Name\Space\`{`OneItem, AnotherItem}
                    if ( $isNamespace ) {
                        $inNamespace = true;
                    }
                break;

                case ';';
                case ',':
                case '}':
                    if ( $isUse ) {
                        if ( $replacedClass ) {
                            $uses[$replacedClass] = $latestString;
                            $replacedClass = "";
                        }
                        elseif ( $latestString ) {
                            $uses[implode("\\", array_merge($statement, [ $latestString ]))] = $latestString;
                        }
                    }

                    if ( $inNamespace ) {
                        $latestString = "";

                        # \My\Name\Space\{OneItem, AnotherItem`}` <- closing a sub-namespace
                        if ( $token !== "}" ) {
                            break;
                        }
                    }

                case T_OPEN_TAG:
                default:
                    $statement = [];
                    $latestString = "";
                    $replacedClass = null;
                    $isNamespace = $inNamespace = false;
                    $isUse = ( $isUse ?? false ) && ( $token === ',' );
                break;
            }
		}

        return $uses;
    }
}
