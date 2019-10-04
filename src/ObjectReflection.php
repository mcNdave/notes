<?php

namespace Notes;

use Reflector, ReflectionClass, ReflectionProperty, ReflectionMethod;

class ObjectReflection {

    public AnnotationReader $annotationReader;

    public function __construct($class, AnnotationReader $annotationReader = null) {
        $this->classReflection = $class instanceof ReflectionClass ? $class : new ReflectionClass($class);
        $this->annotationReader = $annotationReader ?: AnnotationReader::fromClass($class);
    }

    public static function fromClass($class) : self
    {
        return new static($class);
    }

    public function read() : array
    {
        return [
            'uses'     => $this->gatherUses(true),
            'class'    => $this->gatherClass(true),
            'method'   => $this->gatherMethods(true),
            'property' => $this->gatherProperties(true),
        ];
    }

    public function gatherUses(bool $full = true) : array
    {
        $list = [];

        if ( $full ) {
            if ( $parentClass = $this->classReflection->getParentClass() ) {
                $list = static::fromClass($parentClass)->gatherUses(true);
            }

            foreach($this->classReflection->getTraits() as $trait) {
                $list = array_merge($list, static::fromClass($trait)->gatherUses(true));
            }
        }

        return array_merge($this->getUsesStatements(), $list);
    }

    public function gatherClass(bool $full = true) : array
    {
        $class = [];

        if ( $full ) {
            if ( $parentClass = $this->classReflection->getParentClass() ) {
                $class = static::fromClass($parentClass)->gatherClass(true);
            }

            $itemName = function($item) {
                return $item->getName();
            };
        }

        return [
            'tags' => array_merge($class, $this->annotationReader->getClass($this->classReflection))
        ] + ( ! $full ? [] : [
            'traits' => array_map($itemName, $this->classReflection->getTraits()),
            'interfaces' => array_map($itemName, $this->classReflection->getInterfaces()),
        ]);
    }

    public function gatherProperties(bool $full = true, int $filter =
        ReflectionProperty::IS_PUBLIC    |
        ReflectionProperty::IS_PROTECTED |
        ReflectionProperty::IS_PRIVATE
    ) : array
    {
        $properties = [];
        $defaultValues = $this->classReflection->getDefaultProperties();

        if ( $full ) {
            if ( $parentClass = $this->classReflection->getParentClass() ) {
                $properties = static::fromClass($parentClass)->gatherProperties($full, $filter);
            }
        }

        $properties = array_merge($properties, $this->classReflection->getProperties($filter));

        $list = [];

        foreach($properties as $property) {
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

        return $list;
    }

    public function gatherMethods(bool $full = true, int $filter =
        ReflectionMethod::IS_PUBLIC    |
        ReflectionMethod::IS_PROTECTED |
        ReflectionMethod::IS_PRIVATE   |
        ReflectionMethod::IS_STATIC
    ) : array
    {
        $list = $methods = [];

        if ( $full ) {
            if ( $parentClass = $this->classReflection->getParentClass() ) {
                $methods = static::fromClass($parentClass)->gatherMethods($full, $filter);
            }
        }

        $methods = array_merge($methods, $this->classReflection->getMethods($filter));

        foreach($methods as $method) {
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

        return $list;
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
