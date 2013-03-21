<?php

namespace Sabre\CalDAV\XML\Property;

use
    Sabre\XML\Element,
    Sabre\XML\Reader,
    Sabre\XML\Writer,
    Sabre\CalDAV\Plugin,
    Sabre\CalDAV\SharingPlugin;

/**
 * Invite property
 *
 * This property encodes the 'invite' property, as defined by
 * the 'caldav-sharing-02' spec, in the http://calendarserver.org/ns/
 * namespace.
 *
 * @see https://trac.calendarserver.org/browser/CalendarServer/trunk/doc/Extensions/caldav-sharing-02.txt
 * @copyright Copyright (C) 2007-2013 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/)
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Invite implements Element {

    /**
     * The list of users a calendar has been shared to.
     *
     * @var array
     */
    protected $users;

    /**
     * The organizer contains information about the person who shared the
     * object.
     *
     * @var array
     */
    protected $organizer;

    /**
     * Creates the property.
     *
     * Users is an array. Each element of the array has the following
     * properties:
     *
     *   * href - Often a mailto: address
     *   * commonName - Optional, for example a first and lastname for a user.
     *   * status - One of the SharingPlugin::STATUS_* constants.
     *   * readOnly - true or false
     *   * summary - Optional, description of the share
     *
     * The organizer key is optional to specify. It's only useful when a
     * 'sharee' requests the sharing information.
     *
     * The organizer may have the following properties:
     *   * href - Often a mailto: address.
     *   * commonName - Optional human-readable name.
     *   * firstName - Optional first name.
     *   * lastName - Optional last name.
     *
     * If you wonder why these two structures are so different, I guess a
     * valid answer is that the current spec is still a draft.
     *
     * @param array $users
     */
    public function __construct(array $users, array $organizer = null) {

        $this->users = $users;
        $this->organizer = $organizer;

    }

    /**
     * Returns the list of users, as it was passed to the constructor.
     *
     * @return array
     */
    public function getValue() {

        return $this->users;

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

        $cs = '{' . Plugin::NS_CALENDARSERVER . '}';

        if (!is_null($this->organizer)) {

            $writer->startElement($cs . 'organizer');
            $writer->writeElement('{DAV:}href', $this->organizer['href']);

            if (isset($this->organizer['commonName']) && $this->organizer['commonName']) {
                $writer->writeElement($cs . 'common-name', $this->organizer['commonName']);
            }
            if (isset($this->organizer['firstName']) && $this->organizer['firstName']) {
                $writer->writeElement($cs . 'first-name', $this->organizer['firstName']);
            }
            if (isset($this->organizer['lastName']) && $this->organizer['lastName']) {
                $writer->writeElement($cs . 'last-name', $this->organizer['lastName']);
            }
            $writer->endElement(); // organizer

        }

        foreach($this->users as $user) {

            $writer->startElement($cs . 'user');
            $writer->writeElement('{DAV:}href', $user['href']);
            if (isset($user['commonName']) && $user['commonName']) {
                $writer->writeElement($cs . 'common-name', $user['commonName']);
            }
            switch($user['status']) {

                case SharingPlugin::STATUS_ACCEPTED :
                    $writer->writeElement($cs . 'invite-accepted');
                    break;
                case SharingPlugin::STATUS_DECLINED :
                    $writer->writeElement($cs . 'invite-declined');
                    break;
                case SharingPlugin::STATUS_NORESPONSE :
                    $writer->writeElement($cs . 'invite-noresponse');
                    break;
                case SharingPlugin::STATUS_INVALID :
                    $writer->writeElement($cs . 'invite-invalid');
                    break;
            }

            $writer->startElement($cs . 'access');
            if ($user['readOnly']) {
                $writer->writeElement($cs . 'read');
            } else {
                $writer->writeElement($cs . 'read-write');
            }
            $writer->endElement(); // access

            if (isset($user['summary']) && $user['summary']) {
                $writer->writeElement($cs . 'summary', $user['summary']);
            }

            $writer->endElement(); //user

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

        $cs = '{' . Plugin::NS_CALENDARSERVER . '}';

        $users = [];

        foreach($reader->parseInnerTree() as $elem) {

            if ($elem['name']!==$cs.'user')
                continue;

            $user = [
                'href' => null,
                'commonName' => null,
                'readOnly' => null,
                'summary' => null,
                'status' => null,
            ];

            foreach($elem['value'] as $userElem) {

                switch($userElem['name']) {
                    case $cs . 'invite-accepted' :
                        $user['status'] = SharingPlugin::STATUS_ACCEPTED;
                        break;
                    case $cs . 'invite-declined' :
                        $user['status'] = SharingPlugin::STATUS_DECLINED;
                        break;
                    case $cs . 'invite-noresponse' :
                        $user['status'] = SharingPlugin::STATUS_NORESPONSE;
                        break;
                    case $cs . 'invite-invalid' :
                        $user['status'] = SharingPlugin::STATUS_INVALID;
                        break;
                    case '{DAV:}href' :
                        $user['href'] = $userElem['value'];
                        break;
                    case $cs . 'common-name' :
                        $user['commonName'] = $userElem['value'];
                        break;
                    case $cs . 'access' :
                        foreach($userElem['value'] as $accessHref) {
                            if ($accessHref['name'] === $cs . 'read') {
                                $user['readOnly'] = true;
                            }
                        }
                        break;
                    case $cs . 'summary' :
                        $user['summary'] = $userElem['value'];
                        break;

                }

            }
            if (!$user['status']) {
                throw new \InvalidArgumentException('Every user must have one of cs:invite-accepted, cs:invite-declined, cs:invite-noresponse or cs:invite-invalid');
            }

            $users[] = $user;

        }

        return new self($users);

    }

}
