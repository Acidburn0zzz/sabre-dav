<?php

namespace Sabre\DAV;

/**
 * This class holds all the information about a PROPFIND request.
 *
 * It contains the type of PROPFIND request, which properties were requested
 * and also the returned items.
 */
class PropFind {

    /**
     * A normal propfind
     */
    const NORMAL = 0;

    /**
     * An allprops request.
     *
     * While this was originally intended for instructing the server to really
     * fetch every property, because it was used so often and it's so heavy
     * this turned into a small list of default properties after a while.
     *
     * So 'all properties' now means a hardcoded list.
     */
    const ALLPROPS = 1;

    /**
     * A propname request. This just returns a list of properties that are
     * defined on a node, without their values.
     */
    const PROPNAME = 2;


    /**
     * Creates the PROPFIND object
     *
     * @param string $path
     * @param array $properties
     * @param int $depth
     * @param int $requestType
     */
    public function __construct($path, array $properties, $depth = 0, $requestType = self::NORMAL) {

        $this->path = $path;
        $this->properties = $properties;
        $this->depth = $depth;
        $this->requestType = $requestType;

        if($requestType === self::ALLPROPS) {
            $this->properties = [
                '{DAV:}getlastmodified',
                '{DAV:}getcontentlength',
                '{DAV:}resourcetype',
                '{DAV:}quota-used-bytes',
                '{DAV:}quota-available-bytes',
                '{DAV:}getetag',
                '{DAV:}getcontenttype',
           ];
        }

        foreach($this->properties as $propertyName) {

            // Seeding properties with 404's.
            $this->result[$propertyName] = [404, null];

        }
        $this->itemsLeft = count($this->result);

    }

    /**
     * Handles a specific property.
     *
     * This method checks wether the specified property was requested in this
     * PROPFIND request, and if so, it will call the callback and use the
     * return value for it's value.
     *
     * Example:
     *
     * $propFind->handle('{DAV:}displayname', function() {
     *      return 'hello';
     * });
     *
     * Note that handle will only work the first time. If null is returned, the
     * value is ignored.
     *
     * It's also possible to not pass a callback, but immediately pass a value
     *
     * @param string $propertyName
     * @param mixed $valueOrCallBack
     * @return void
     */
    public function handle($propertyName, $valueOrCallBack) {

        if ($this->itemsLeft && isset($this->result[$propertyName]) && $this->result[$propertyName][0] === 404) {
            if (is_callable($valueOrCallBack)) {
                $value = $valueOrCallBack();
            } else {
                $value = $valueOrCallBack;
            }
            if (!is_null($value)) {
                $this->itemsLeft--;
                $this->result[$propertyName] = [200, $value];
            }
        }

    }

    /**
     * Sets the value of the property
     *
     * If status is not supplied, the status will default to 200 for non-null
     * properties, and 404 for null properties.
     *
     * @param string $propertyName
     * @param mixed $value
     * @param int $status
     * @return void
     */
    public function set($propertyName, $value, $status = null) {

        if (isset($this->result[$propertyName])) {
            if (is_null($status)) {
                $status = is_null($value) ? 404 : 200;
            }
            if ($status!==404 && $this->result[$propertyName][0]===404) {
                $this->itemsLeft--;
            } elseif ($status === 404 && $this->result[$propertyName][0] !== 404) {
                $this->itemsLeft++;
            }
            $this->result[$propertyName] = [$status, $value];
        }

    }

    /**
     * Returns the current value for a property.
     *
     * @param string $propertyName
     * @return mixed
     */
    public function get($propertyName) {

        return isset($this->result[$propertyName])?$this->result[$propertyName][1]:null;

    }

    /**
     * Returns the current status code for a property name.
     *
     * If the property does not appear in the list of requested properties,
     * null will be returned.
     *
     * @param string $propertyName
     * @return int|null
     */
    public function getStatus($propertyName) {

        return isset($this->result[$propertyName])?$this->result[$propertyName][0]:null;

    }

    /**
     * Updates the path for this PROPFIND.
     *
     * @param string $path
     * @return void
     */
    public function setPath($path) {

        $this->path = $path;

    }

    /**
     * Returns the path this PROPFIND request is for.
     *
     * @return string
     */
    public function getPath() {

        return $this->path;

    }

    /**
     * Returns the depth of this propfind request.
     *
     * @return int
     */
    public function getDepth() {

        return $this->depth;

    }

    /**
     * Updates the depth of this propfind request.
     *
     * @param int $depth
     * @return void
     */
    public function setDepth($depth) {

        $this->depth = $depth;

    }

    /**
     * Returns all propertynames that have a 404 status, and thus don't have a
     * value yet.
     *
     * @return array
     */
    public function get404Properties() {

        if ($this->itemsLeft === 0) {
            return [];
        }
        $result = [];
        foreach($this->result as $propertyName=>$stuff) {
            if ($stuff[0]===404) {
                $result[] = $propertyName;
            }
        }
        return $result;

    }

    /**
     * Returns the full list of requested properties.
     *
     * This returns just their names, not a status or value.
     *
     * @return array
     */
    public function getRequestedProperties() {

        return $this->properties;

    }

    /**
     * Returns a result array that's often used in multistatus responses.
     *
     * The array uses status codes as keys, and property names and value pairs
     * as the value of the top array.. such as :
     *
     * [
     *  200 => [ '{DAV:}displayname' => 'foo' ],
     * ]
     *
     * @return array
     */
    public function getResultForMultiStatus() {

        $r = [
            200 => [],
            404 => [],
        ];
        foreach($this->result as $propertyName=>$info) {
            if (!isset($r[$info[0]])) {
                $r[$info[0]] = [$propertyName => $info[1]];
            } else {
                $r[$info[0]][$propertyName] = $info[1];
            }
        }
        return $r;

    }

    /**
     * The path that we're fetching properties for.
     *
     * @var string
     */
    protected $path;

    /**
     * The Depth of the request.
     *
     * 0 means only the current item. 1 means the current item + its children.
     * It can also be DEPTH_INFINITY if this is enabled in the server.
     *
     * @var int
     */
    protected $depth = 0;

    /**
     * The type of request. See the TYPE constants
     */
    protected $requestType;

    /**
     * A list of requested properties
     *
     * @var array
     */
    protected $properties = [];

    /**
     * The result of the operation.
     *
     * The keys in this array are property names.
     * The values are an array with two elements: the http status code and then
     * optionally a value.
     *
     * Example:
     *
     * [
     *    "{DAV:}owner" : [404],
     *    "{DAV:}displayname" : [200, "Admin"]
     * ]
     *
     * @var array
     */
    protected $result = [];

    /**
     * This is used as an internal counter for the number of properties that do
     * not yet have a value.
     *
     * @var int
     */
    protected $itemsLeft;

}
