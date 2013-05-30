<?php

namespace Sabre\CalDAV\XML\Notification;

use
    Sabre\XML\Reader,
    Sabre\XML\Writer,
    Sabre\CalDAV\Plugin,
    Sabre\DAV\Exception\CannotSerialize;


/**
 * SystemStatus notification
 *
 * This notification can be used to indicate to the user that the system is
 * down.
 *
 * @copyright Copyright (C) 2007-2013 fruux GmbH (https://fruux.com/).
 * @author Evert Pot (http://evertpot.com/)
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class SystemStatus implements NotificationInterface {

    const TYPE_LOW = 1;
    const TYPE_MEDIUM = 2;
    const TYPE_HIGH = 3;

    /**
     * A unique id
     *
     * @var string
     */
    protected $id;

    /**
     * The type of alert. This should be one of the TYPE_ constants.
     *
     * @var int
     */
    protected $type;

    /**
     * A human-readable description of the problem.
     *
     * @var string
     */
    protected $description;

    /**
     * A url to a website with more information for the user.
     *
     * @var string
     */
    protected $href;

    /**
     * Notification Etag
     *
     * @var string
     */
    protected $etag;

    /**
     * Creates the notification.
     *
     * Some kind of unique id should be provided. This is used to generate a
     * url.
     *
     * @param string $id
     * @param string $etag
     * @param int $type
     * @param string $description
     * @param string $href
     */
    public function __construct($id, $etag, $type = self::TYPE_HIGH, $description = null, $href = null) {

        $this->id = $id;
        $this->type = $type;
        $this->description = $description;
        $this->href = $href;
        $this->etag = $etag;

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

        switch($this->type) {
            case self::TYPE_LOW :
                $type = 'low';
                break;
            case self::TYPE_MEDIUM :
                $type = 'medium';
                break;
            default :
            case self::TYPE_HIGH :
                $type = 'high';
                break;
        }

        $writer->startElement('{' . Plugin::NS_CALENDARSERVER .'}systemstatus');
        $writer->writeAttribute('type', $type);
        $writer->endElement();

    }

    /**
     * This method serializes the entire notification, as it is used in the
     * response body.
     *
     * @param Writer $writer
     * @return void
     */
    public function serializeFullXml(Writer $writer) {

        $cs = '{' . Plugin::NS_CALENDARSERVER .'}';
        switch($this->type) {
            case self::TYPE_LOW :
                $type = 'low';
                break;
            case self::TYPE_MEDIUM :
                $type = 'medium';
                break;
            default :
            case self::TYPE_HIGH :
                $type = 'high';
                break;
        }

        $writer->startElement($cs .'systemstatus');
        $writer->writeAttribute('type', $type);


        if ($this->description) {
            $writer->writeElement($cs . 'description', $this->description);
        }
        if ($this->href) {
            $writer->writeElement('{DAV:}href', $this->href);
        }

        $writer->endElement(); // systemstatus

    }

    /**
     * Returns a unique id for this notification
     *
     * This is just the base url. This should generally be some kind of unique
     * id.
     *
     * @return string
     */
    public function getId() {

        return $this->id;

    }

    /*
     * Returns the ETag for this notification.
     *
     * The ETag must be surrounded by literal double-quotes.
     *
     * @return string
     */
    public function getETag() {

        return $this->etag;

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

        throw new CannotDeserialize('This element does not have a deserializer');

    }
}
