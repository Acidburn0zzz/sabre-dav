<?php

namespace Sabre\CalDAV\XML\Request;

use
    Sabre\XML\Element,
    Sabre\XML\Reader,
    Sabre\XML\Writer,
    Sabre\DAV\Exception\CannotSerialize,
    Sabre\DAV\Exception\BadRequest,
    Sabre\CalDAV\Plugin;

/**
 * CalendarQueryReport request parser.
 *
 * This class parses the {urn:ietf:params:xml:ns:caldav}calendar-query
 * REPORT, as defined in:
 *
 * https://tools.ietf.org/html/rfc4791#section-7.9
 *
 * @copyright Copyright (C) 2007-2013 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/)
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class CalendarQueryReport implements Element {

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
     * List of property/component filters.
     *
     * @var array
     */
    public $filter;

    /**
     * If the calendar data must be expanded, this will contain an array with 2
     * elements: start and end.
     *
     * Each may be a DateTime or null.
     *
     * @var array|null
     */
    public $expand = null;

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

        $properties = null;
        $expand = false;
        $filter = null;

        if (!is_array($elems)) $elems = [];

        foreach($elems as $elem) {

            switch($elem['name']) {

                case '{DAV:}prop' :
                    if (isset($elem['value']['{' . Plugin::NS_CALDAV . '}calendar-data']['expand'])) {
                        $expand = $elem['value']['{' . Plugin::NS_CALDAV . '}calendar-data']['expand'];
                    }
                    $properties = array_keys($elem['value']);
                    break;
                case '{'.Plugin::NS_CALDAV.'}filter' :
                    foreach($elem['value'] as $subElem) {
                        if ($subElem['name'] === '{' . Plugin::NS_CALDAV . '}comp-filter') {
                            if (!is_null($filter)) {
                                throw new BadRequest('Only one top-level comp-filter may be defined');
                            }
                            $filter = $subElem['value'];
                        }
                    }
                    break;

            }

        }

        if (is_null($filter)) {
            throw new BadRequest('The {' . Plugin::NS_CALDAV . '}filter element is required for this request');
        }

        $obj = new self();
        $obj->properties = $properties;
        $obj->filter = $filter;
        $obj->expand = $expand;

        return $obj;

    }

}
