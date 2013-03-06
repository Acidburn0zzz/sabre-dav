<?php

namespace Sabre\DAVACL\XML\Request;

use
    Sabre\XML\Element,
    Sabre\XML\Reader,
    Sabre\XML\Writer,
    Sabre\DAV\Exception\CannotSerialize,
    Sabre\DAV\Exception\BadRequest;

/**
 * ExpandProperty request parser.
 *
 * This class parses the {DAV:}expand-property REPORT, as defined in:
 *
 * http://tools.ietf.org/html/rfc3253#section-3.8
 *
 * @copyright Copyright (C) 2007-2013 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/)
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class ExpandPropertyReport implements Element {

    /**
     * An array with requested properties.
     *
     * The requested properties will be used as keys in this array. The value
     * is normally null.
     *
     * If the value is an array though, it means the property must be expanded.
     * Within the array, the sub-properties, which themselves may be null or
     * arrays.
     *
     * @var array
     */
    public $properties;

    /**
     * The serialize method is called during xml writing.
     *
     * It should use the $writer argument to encode this object into XML.
     *
     * Important note: it is not needed to create the parent element. The
     * parent element is already created, and we only have to worry about
     * attributes, child elements and text (if any).
     *
     * Important note 2: If you are writing any new elements, you are also
     * responsible for closing them.
     *
     * @param Writer $writer
     * @return void
     */
    public function serializeXml(Writer $writer) {

        throw new CannotSerialize('This element cannot be serialized.');

    }

    /**
     * The deserialize method is called during xml parsing.
     *
     * This method is called statictly, this is because in theory this method
     * may be used as a type of constructor, or factory method.
     *
     * Often you want to return an instance of the current class, but you are
     * free to return other data as well.
     *
     * Important note 2: You are responsible for advancing the reader to the
     * next element. Not doing anything will result in a never-ending loop.
     *
     * If you just want to skip parsing for this element altogether, you can
     * just call $reader->next();
     *
     * $reader->parseInnerTree() will parse the entire sub-tree, and advance to
     * the next element.
     *
     * @param Reader $reader
     * @return mixed
     */
    static public function deserializeXml(Reader $reader) {

        $elems = $reader->parseInnerTree();

        $obj = new self();
        $obj->properties = self::traverse($elems);

        return $obj;

    }

    /**
     * This method is used by deserializeXml, to recursively parse the
     * {DAV:}property elements.
     *
     * @param array $elems
     * @return void
     */
    static private function traverse($elems) {

        $result = [];

        foreach($elems as $elem) {

            if ($elem['name'] !== '{DAV:}property') {
                continue;
            }

            $namespace = isset($elem['attributes']['namespace']) ?
                $elem['attributes']['namespace'] :
                'DAV:';

            $propName = '{' . $namespace . '}' . $elem['attributes']['name'];

            $value = null;
            if (is_array($elem['value'])) {
                $value = self::traverse($elem['value']);
            }

            $result[$propName] = $value;

        }

        return $result;

    }

}