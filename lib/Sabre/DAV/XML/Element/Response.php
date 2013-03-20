<?php

namespace Sabre\DAV\XML\Element;

use
    Sabre\XML\Element,
    Sabre\XML\Reader,
    Sabre\XML\Writer,
    Sabre\DAV\Exception\CannotSerialize;

/**
 * WebDAV {DAV:}response parser
 *
 * This class parses the {DAV:}response element, as defined in:
 *
 * https://tools.ietf.org/html/rfc4918#section-14.24
 *
 * @copyright Copyright (C) 2007-2013 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/)
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Response implements Element {

    /**
     * Url for the response
     *
     * @var string
     */
    protected $href;

    /**
     * Propertylist, ordered by HTTP status code
     *
     * @var array
     */
    protected $responseProperties;

    /**
     * The HTTP status for an entire response.
     *
     * This is currently only used in WebDAV-Sync
     *
     * @var string
     */
    protected $httpStatus;

    /**
     * The href argument is a url relative to the root of the server. This
     * class will calculate the full path.
     *
     * The responseProperties argument is a list of properties
     * within an array with keys representing HTTP status codes
     *
     * Besides specific properties, the entire {DAV:}response element may also
     * have a http status code.
     * In most cases you don't need it.
     *
     * This is currently used by the Sync extension to indicate that a node is
     * deleted.
     *
     * @param string $href
     * @param array $responseProperties
     * @param string $httpStatus
     */
    public function __construct($href, array $responseProperties, $httpStatus = null) {

        $this->href = $href;
        $this->responseProperties = $responseProperties;
        $this->httpStatus = $httpStatus;

    }

    /**
     * Returns the url
     *
     * @return string
     */
    public function getHref() {

        return $this->href;

    }

    /**
     * Returns the httpStatus value
     *
     * @return string
     */
    public function getHttpStatus() {

        return $this->httpStatus;

    }

    /**
     * Returns the property list
     *
     * @return array
     */
    public function getResponseProperties() {

        return $this->responseProperties;

    }


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

        if ($status = $this->getHTTPStatus()) {
            $writer->writeElement('{DAV:}status', \Sabre\HTTP\Response::getStatusMessage($status));
        }
        $writer->writeElement('{DAV:}href', $writer->baseUri . $this->getHref());
        foreach($this->getResponseProperties() as $status => $properties) {

            // Skipping empty lists
            if (!$properties) {
                continue;
            }
            $writer->startElement('{DAV:}propstat');
            $writer->writeElement('{DAV:}prop', $properties);
            $writer->writeElement('{DAV:}status', \Sabre\HTTP\Response::getStatusMessage($status));
            $writer->endElement(); // {DAV:}propstat

        }

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

        $href = null;
        $propertyLists = [];
        $statusCode = null;

        foreach($elems as $elem) {

            switch($elem['name']) {

                case '{DAV:}href' :
                    $href = $elem['value'];
                    break;
                case '{DAV:}propstat' :
                    $status = $elem['value']['{DAV:}status'];
                    list(, $status, ) = explode(' ', $status,3);
                    $properties = $elem['value']['{DAV:}prop'];
                    $propertyLists[$status] = $properties;
                    break;
                case '{DAV:}status' :
                    list(, $statusCode, ) = explode(' ', $elem['value'],3);
                    break;

            }

        }

        return new self($href, $propertyLists, $statusCode);

    }

}
