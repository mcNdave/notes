<?php

namespace Notes;

use Reflector, ReflectionClass, ReflectionProperty, ReflectionMethod;

class AnnotationReader
{
    const PHP_TYPES = [ "string", "int", "float", "object", "double", "closure", ];

    public string $class;

    public function __construct($class) {
        $this->class = $class;
    }

    public static function fromClass($class) : self
    {
        return new static($class);
    }

    public function getProperty(ReflectionProperty $property)
    {
        return $this->parseDocComment($property);
    }

    public function getClass(ReflectionClass $class)
    {
        return $this->parseDocComment($class);
    }

    public function getMethod(ReflectionMethod $method)
    {
        return $this->parseDocComment($method);
    }

    protected function parseDocComment(Reflector $reflect)
    {
        $namespace = $this->getObjectNamespace($reflect);
        $tags = [];

        foreach(preg_split("/\r\n|\n|\r/", $reflect->getDocComment()) as $line) {
            $line = ltrim($line, "* \t\/");
            $line = rtrim($line, "\t ");

            if ( substr($line, 0, 1) === '@' ) {
                $line = ltrim($line, '@');

                $open = strpos($line, "(");
                $close = strrpos($line, ")");

                if ( ! in_array(false, [ $open, $close ], true) && ( ++$open !== $close ) ) {
                    $arguments = substr($line, $open, $close - $open);

                    try {
                        $tags[] = [
                            'tag' => substr($line, 0, $open - 1),
                            'arguments' => eval("namespace $namespace; return [ $arguments ];"),
                        ];
                    }
                    catch(\Throwable $error) {
                        throw new \InvalidArgumentException("An error occured while parsing annotation from '" . $this->getObjectName($reflect) . "' : @$line -- " . $error->getMessage());
                    }
                }
                else {
                    $tags[] = [
                        'tag' => $line,
                        'arguments' => [],
                    ];
                }
            }
        }

        return $tags;
    }

    protected function getObjectName(Reflector $reflect) : string
    {
        switch(true) {
            case $reflect instanceof ReflectionMethod :
            case $reflect instanceof ReflectionProperty :
                return $reflect->class . "::" . $reflect->name;

            case $reflect instanceof ReflectionClass :
                return $reflect->name;
        }
    }

    protected function getObjectNamespace(Reflector $reflect) : string
    {
        switch(true) {
            case $reflect instanceof ReflectionMethod :
            case $reflect instanceof ReflectionProperty :
                return $reflect->getDeclaringClass()->getNamespaceName();

            case $reflect instanceof ReflectionClass :
                return $reflect->getNamespaceName();
        }
    }
}
