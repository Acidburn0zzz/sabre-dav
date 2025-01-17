<?php

namespace Sabre\DAV;

use
    Sabre\HTTP\RequestInterface,
    Sabre\HTTP\ResponseInterface;

/**
 * The core plugin provides all the basic features for a WebDAV server.
 *
 * @copyright Copyright (C) 2007-2014 fruux GmbH. All rights reserved.
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class CorePlugin extends ServerPlugin {

    /**
     * Reference to server object.
     *
     * @var Server
     */
    protected $server;

    /**
     * Sets up the plugin
     *
     * @param Server $server
     * @return void
     */
    public function initialize(Server $server) {

        $this->server = $server;
        $server->on('method:GET',       [$this, 'httpGet']);
        $server->on('method:OPTIONS',   [$this, 'httpOptions']);
        $server->on('method:HEAD',      [$this, 'httpHead']);
        $server->on('method:DELETE',    [$this, 'httpDelete']);
        $server->on('method:PROPFIND',  [$this, 'httpPropfind']);
        $server->on('method:PROPPATCH', [$this, 'httpProppatch']);
        $server->on('method:PUT',       [$this, 'httpPut']);
        $server->on('method:MKCOL',     [$this, 'httpMkcol']);
        $server->on('method:MOVE',      [$this, 'httpMove']);
        $server->on('method:COPY',      [$this, 'httpCopy']);
        $server->on('method:REPORT',    [$this, 'httpReport']);

        $server->on('propPatch', [$this, 'propPatchProtectedPropertyCheck'], 90);
        $server->on('propPatch', [$this, 'propPatchNodeUpdate'], 200);
        $server->on('propFind',  [$this, 'propFind']);
        $server->on('propFind',  [$this, 'propFindNode'], 120);

    }

    /**
     * Returns a plugin name.
     *
     * Using this name other plugins will be able to access other plugins
     * using DAV\Server::getPlugin
     *
     * @return string
     */
    public function getPluginName() {

        return 'core';

    }

    /**
     * This is the default implementation for the GET method.
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return bool
     */
    public function httpGet(RequestInterface $request, ResponseInterface $response) {

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path,0);

        if (!$node instanceof IFile) return;

        $body = $node->get();

        // Converting string into stream, if needed.
        if (is_string($body)) {
            $stream = fopen('php://temp','r+');
            fwrite($stream,$body);
            rewind($stream);
            $body = $stream;
        }

        /*
         * TODO: getetag, getlastmodified, getsize should also be used using
         * this method
         */
        $httpHeaders = $this->server->getHTTPHeaders($path);

        /* ContentType needs to get a default, because many webservers will otherwise
         * default to text/html, and we don't want this for security reasons.
         */
        if (!isset($httpHeaders['Content-Type'])) {
            $httpHeaders['Content-Type'] = 'application/octet-stream';
        }


        if (isset($httpHeaders['Content-Length'])) {

            $nodeSize = $httpHeaders['Content-Length'];

            // Need to unset Content-Length, because we'll handle that during figuring out the range
            unset($httpHeaders['Content-Length']);

        } else {
            $nodeSize = null;
        }

        $response->addHeaders($httpHeaders);

        $range = $this->server->getHTTPRange();
        $ifRange = $request->getHeader('If-Range');
        $ignoreRangeHeader = false;

        // If ifRange is set, and range is specified, we first need to check
        // the precondition.
        if ($nodeSize && $range && $ifRange) {

            // if IfRange is parsable as a date we'll treat it as a DateTime
            // otherwise, we must treat it as an etag.
            try {
                $ifRangeDate = new \DateTime($ifRange);

                // It's a date. We must check if the entity is modified since
                // the specified date.
                if (!isset($httpHeaders['Last-Modified'])) $ignoreRangeHeader = true;
                else {
                    $modified = new \DateTime($httpHeaders['Last-Modified']);
                    if($modified > $ifRangeDate) $ignoreRangeHeader = true;
                }

            } catch (\Exception $e) {

                // It's an entity. We can do a simple comparison.
                if (!isset($httpHeaders['ETag'])) $ignoreRangeHeader = true;
                elseif ($httpHeaders['ETag']!==$ifRange) $ignoreRangeHeader = true;
            }
        }

        // We're only going to support HTTP ranges if the backend provided a filesize
        if (!$ignoreRangeHeader && $nodeSize && $range) {

            // Determining the exact byte offsets
            if (!is_null($range[0])) {

                $start = $range[0];
                $end = $range[1]?$range[1]:$nodeSize-1;
                if($start >= $nodeSize)
                    throw new Exception\RequestedRangeNotSatisfiable('The start offset (' . $range[0] . ') exceeded the size of the entity (' . $nodeSize . ')');

                if($end < $start) throw new Exception\RequestedRangeNotSatisfiable('The end offset (' . $range[1] . ') is lower than the start offset (' . $range[0] . ')');
                if($end >= $nodeSize) $end = $nodeSize-1;

            } else {

                $start = $nodeSize-$range[1];
                $end  = $nodeSize-1;

                if ($start<0) $start = 0;

            }

            // New read/write stream
            $newStream = fopen('php://temp','r+');

            // stream_copy_to_stream() has a bug/feature: the `whence` argument
            // is interpreted as SEEK_SET (count from absolute offset 0), while
            // for a stream it should be SEEK_CUR (count from current offset).
            // If a stream is nonseekable, the function fails. So we *emulate*
            // the correct behaviour with fseek():
            if ($start > 0) {
                if (($curOffs = ftell($body)) === false) $curOffs = 0;
                fseek($body, $start - $curOffs, SEEK_CUR);
            }
            stream_copy_to_stream($body, $newStream, $end-$start+1);
            rewind($newStream);

            $response->setHeader('Content-Length', $end-$start+1);
            $response->setHeader('Content-Range','bytes ' . $start . '-' . $end . '/' . $nodeSize);
            $response->setStatus(206);
            $response->setBody($newStream);

        } else {

            if ($nodeSize) $response->setHeader('Content-Length',$nodeSize);
            $response->setStatus(200);
            $response->setBody($body);

        }
        // Sending back false will interupt the event chain and tell the server
        // we've handled this method.
        return false;

    }

    /**
     * HTTP OPTIONS
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return bool
     */
    public function httpOptions(RequestInterface $request, ResponseInterface $response) {

        $methods = $this->server->getAllowedMethods($request->getPath());

        $response->setHeader('Allow',strtoupper(implode(', ',$methods)));
        $features = ['1','3', 'extended-mkcol'];

        foreach($this->server->getPlugins() as $plugin) {
            $features = array_merge($features,$plugin->getFeatures());
        }

        $response->setHeader('DAV',implode(', ',$features));
        $response->setHeader('MS-Author-Via','DAV');
        $response->setHeader('Accept-Ranges','bytes');
        if (Server::$exposeVersion) {
            $response->setHeader('X-Sabre-Version',Version::VERSION);
        }
        $response->setHeader('Content-Length',0);
        $response->setStatus(200);

        // Sending back false will interupt the event chain and tell the server
        // we've handled this method.
        return false;

    }

    /**
     * HTTP HEAD
     *
     * This method is normally used to take a peak at a url, and only get the HTTP response headers, without the body
     * This is used by clients to determine if a remote file was changed, so they can use a local cached version, instead of downloading it again
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return bool
     */
    public function httpHead(RequestInterface $request, ResponseInterface $response) {

        $path = $request->getPath();

        $node = $this->server->tree->getNodeForPath($path);

        /* This information is only collection for File objects.
         * Ideally we want to throw 405 Method Not Allowed for every
         * non-file, but MS Office does not like this
         */
        if ($node instanceof IFile) {
            $headers = $this->server->getHTTPHeaders($path);
            if (!isset($headers['Content-Type'])) {
                $headers['Content-Type'] = 'application/octet-stream';
            }
            $response->addHeaders($headers);
        }
        $response->setStatus(200);

        // Sending back false will interupt the event chain and tell the server
        // we've handled this method.
        return false;

    }

    /**
     * HTTP Delete
     *
     * The HTTP delete method, deletes a given uri
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return void
     */
    public function httpDelete(RequestInterface $request, ResponseInterface $response) {

        $path = $request->getPath();

        if (!$this->server->emit('beforeUnbind',[$path])) return false;
        $this->server->tree->delete($path);
        $this->server->emit('afterUnbind',[$path]);

        $response->setStatus(204);
        $response->setHeader('Content-Length','0');

        // Sending back false will interupt the event chain and tell the server
        // we've handled this method.
        return false;

    }

    /**
     * WebDAV PROPFIND
     *
     * This WebDAV method requests information about an uri resource, or a list of resources
     * If a client wants to receive the properties for a single resource it will add an HTTP Depth: header with a 0 value
     * If the value is 1, it means that it also expects a list of sub-resources (e.g.: files in a directory)
     *
     * The request body contains an XML data structure that has a list of properties the client understands
     * The response body is also an xml document, containing information about every uri resource and the requested properties
     *
     * It has to return a HTTP 207 Multi-status status code
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return void
     */
    public function httpPropfind(RequestInterface $request, ResponseInterface $response) {

        $path = $request->getPath();

        $requestedProperties = $this->server->parsePropFindRequest(
            $request->getBodyAsString()
        );

        $depth = $this->server->getHTTPDepth(1);
        // The only two options for the depth of a propfind is 0 or 1 - as long as depth infinity is not enabled
        if (!$this->server->enablePropfindDepthInfinity && $depth != 0) $depth = 1;

        $newProperties = $this->server->getPropertiesForPath($path,$requestedProperties,$depth);

        // This is a multi-status response
        $response->setStatus(207);
        $response->setHeader('Content-Type','application/xml; charset=utf-8');
        $response->setHeader('Vary','Brief,Prefer');

        // Normally this header is only needed for OPTIONS responses, however..
        // iCal seems to also depend on these being set for PROPFIND. Since
        // this is not harmful, we'll add it.
        $features = ['1', '3', 'extended-mkcol'];
        foreach($this->server->getPlugins() as $plugin) {
            $features = array_merge($features,$plugin->getFeatures());
        }
        $response->setHeader('DAV',implode(', ',$features));

        $prefer = $this->server->getHTTPPrefer();
        $minimal = $prefer['return-minimal'];

        $data = $this->server->generateMultiStatus($newProperties, $minimal);
        $response->setBody($data);

        // Sending back false will interupt the event chain and tell the server
        // we've handled this method.
        return false;

    }

    /**
     * WebDAV PROPPATCH
     *
     * This method is called to update properties on a Node. The request is an XML body with all the mutations.
     * In this XML body it is specified which properties should be set/updated and/or deleted
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return bool
     */
    public function httpPropPatch(RequestInterface $request, ResponseInterface $response) {

        $path = $request->getPath();

        $newProperties = $this->server->parsePropPatchRequest(
            $request->getBodyAsString()
        );

        $result = $this->server->updateProperties($path, $newProperties);

        $prefer = $this->server->getHTTPPrefer();
        $response->setHeader('Vary','Brief,Prefer');

        if ($prefer['return-minimal']) {

            // If return-minimal is specified, we only have to check if the
            // request was succesful, and don't need to return the
            // multi-status.
            $ok = true;
            foreach($result as $prop=>$code) {
                if ((int)$code > 299) {
                    $ok = false;
                }
            }

            if ($ok) {

                $response->setStatus(204);
                return false;

            }

        }

        $response->setStatus(207);
        $response->setHeader('Content-Type','application/xml; charset=utf-8');


        // Reorganizing the result for generateMultiStatus
        $multiStatus = [];
        foreach($result as $propertyName => $code) {
            if (isset($multiStatus[$code])) {
                $multiStatus[$code][$propertyName] = null;
            } else {
                $multiStatus[$code] = [$propertyName => null];
            }
        }
        $multiStatus['href'] = $path;

        $response->setBody(
            $this->server->generateMultiStatus([$multiStatus])
        );

        // Sending back false will interupt the event chain and tell the server
        // we've handled this method.
        return false;

    }

    /**
     * HTTP PUT method
     *
     * This HTTP method updates a file, or creates a new one.
     *
     * If a new resource was created, a 201 Created status code should be returned. If an existing resource is updated, it's a 204 No Content
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return bool
     */
    public function httpPut(RequestInterface $request, ResponseInterface $response) {

        $body = $request->getBodyAsStream();
        $path = $request->getPath();

        // Intercepting Content-Range
        if ($request->getHeader('Content-Range')) {
            /**
            Content-Range is dangerous for PUT requests:  PUT per definition
            stores a full resource.  draft-ietf-httpbis-p2-semantics-15 says
            in section 7.6:
              An origin server SHOULD reject any PUT request that contains a
              Content-Range header field, since it might be misinterpreted as
              partial content (or might be partial content that is being mistakenly
              PUT as a full representation).  Partial content updates are possible
              by targeting a separately identified resource with state that
              overlaps a portion of the larger resource, or by using a different
              method that has been specifically defined for partial updates (for
              example, the PATCH method defined in [RFC5789]).
            This clarifies RFC2616 section 9.6:
              The recipient of the entity MUST NOT ignore any Content-*
              (e.g. Content-Range) headers that it does not understand or implement
              and MUST return a 501 (Not Implemented) response in such cases.
            OTOH is a PUT request with a Content-Range currently the only way to
            continue an aborted upload request and is supported by curl, mod_dav,
            Tomcat and others.  Since some clients do use this feature which results
            in unexpected behaviour (cf PEAR::HTTP_WebDAV_Client 1.0.1), we reject
            all PUT requests with a Content-Range for now.
            */

            throw new Exception\NotImplemented('PUT with Content-Range is not allowed.');
        }

        // Intercepting the Finder problem
        if (($expected = $request->getHeader('X-Expected-Entity-Length')) && $expected > 0) {

            /**
            Many webservers will not cooperate well with Finder PUT requests,
            because it uses 'Chunked' transfer encoding for the request body.

            The symptom of this problem is that Finder sends files to the
            server, but they arrive as 0-length files in PHP.

            If we don't do anything, the user might think they are uploading
            files successfully, but they end up empty on the server. Instead,
            we throw back an error if we detect this.

            The reason Finder uses Chunked, is because it thinks the files
            might change as it's being uploaded, and therefore the
            Content-Length can vary.

            Instead it sends the X-Expected-Entity-Length header with the size
            of the file at the very start of the request. If this header is set,
            but we don't get a request body we will fail the request to
            protect the end-user.
            */

            // Only reading first byte
            $firstByte = fread($body,1);
            if (strlen($firstByte)!==1) {
                throw new Exception\Forbidden('This server is not compatible with OS/X finder. Consider using a different WebDAV client or webserver.');
            }

            // The body needs to stay intact, so we copy everything to a
            // temporary stream.

            $newBody = fopen('php://temp','r+');
            fwrite($newBody,$firstByte);
            stream_copy_to_stream($body, $newBody);
            rewind($newBody);

            $body = $newBody;

        }

        if ($this->server->tree->nodeExists($path)) {

            $node = $this->server->tree->getNodeForPath($path);

            // If the node is a collection, we'll deny it
            if (!($node instanceof IFile)) throw new Exception\Conflict('PUT is not allowed on non-files.');

            if (!$this->server->updateFile($path, $body, $etag)) {
                return false;
            }

            $response->setHeader('Content-Length','0');
            if ($etag) $response->setHeader('ETag',$etag);
            $response->setStatus(204);

        } else {

            $etag = null;
            // If we got here, the resource didn't exist yet.
            if (!$this->server->createFile($path, $body, $etag)) {
                // For one reason or another the file was not created.
                return false;
            }

            $response->setHeader('Content-Length','0');
            if ($etag) $response->setHeader('ETag', $etag);
            $response->setStatus(201);

        }

        // Sending back false will interupt the event chain and tell the server
        // we've handled this method.
        return false;

    }


    /**
     * WebDAV MKCOL
     *
     * The MKCOL method is used to create a new collection (directory) on the server
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return bool
     */
    public function httpMkcol(RequestInterface $request, ResponseInterface $response) {

        $requestBody = $request->getBodyAsString();
        $path = $request->getPath();

        if ($requestBody) {

            $contentType = $request->getHeader('Content-Type');
            if (strpos($contentType,'application/xml')!==0 && strpos($contentType,'text/xml')!==0) {

                // We must throw 415 for unsupported mkcol bodies
                throw new Exception\UnsupportedMediaType('The request body for the MKCOL request must have an xml Content-Type');

            }

            $dom = XMLUtil::loadDOMDocument($requestBody);
            if (XMLUtil::toClarkNotation($dom->firstChild)!=='{DAV:}mkcol') {

                // We must throw 415 for unsupported mkcol bodies
                throw new Exception\UnsupportedMediaType('The request body for the MKCOL request must be a {DAV:}mkcol request construct.');

            }

            $properties = [];
            foreach($dom->firstChild->childNodes as $childNode) {

                if (XMLUtil::toClarkNotation($childNode)!=='{DAV:}set') continue;
                $properties = array_merge($properties, XMLUtil::parseProperties($childNode, $this->server->propertyMap));

            }
            if (!isset($properties['{DAV:}resourcetype']))
                throw new Exception\BadRequest('The mkcol request must include a {DAV:}resourcetype property');

            $resourceType = $properties['{DAV:}resourcetype']->getValue();
            unset($properties['{DAV:}resourcetype']);

        } else {

            $properties = [];
            $resourceType = ['{DAV:}collection'];

        }

        $result = $this->server->createCollection($path, $resourceType, $properties);

        if (is_array($result)) {
            $response->setStatus(207);
            $response->setHeader('Content-Type','application/xml; charset=utf-8');

            $response->setBody(
                $this->server->generateMultiStatus([$result])
            );

        } else {
            $response->setHeader('Content-Length','0');
            $response->setStatus(201);
        }

        // Sending back false will interupt the event chain and tell the server
        // we've handled this method.
        return false;

    }

    /**
     * WebDAV HTTP MOVE method
     *
     * This method moves one uri to a different uri. A lot of the actual request processing is done in getCopyMoveInfo
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return bool
     */
    public function httpMove(RequestInterface $request, ResponseInterface $response) {

        $path = $request->getPath();

        $moveInfo = $this->server->getCopyAndMoveInfo($request);

        if ($moveInfo['destinationExists']) {

            if (!$this->server->emit('beforeUnbind',[$moveInfo['destination']])) return false;
            $this->server->tree->delete($moveInfo['destination']);
            $this->server->emit('afterUnbind',[$moveInfo['destination']]);

        }

        if (!$this->server->emit('beforeUnbind',[$path])) return false;
        if (!$this->server->emit('beforeBind',[$moveInfo['destination']])) return false;
        $this->server->tree->move($path, $moveInfo['destination']);
        $this->server->emit('afterUnbind',[$path]);
        $this->server->emit('afterBind',[$moveInfo['destination']]);

        // If a resource was overwritten we should send a 204, otherwise a 201
        $response->setHeader('Content-Length','0');
        $response->setStatus($moveInfo['destinationExists']?204:201);

        // Sending back false will interupt the event chain and tell the server
        // we've handled this method.
        return false;

    }

    /**
     * WebDAV HTTP COPY method
     *
     * This method copies one uri to a different uri, and works much like the MOVE request
     * A lot of the actual request processing is done in getCopyMoveInfo
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return bool
     */
    public function httpCopy(RequestInterface $request, ResponseInterface $response) {

        $path = $request->getPath();

        $copyInfo = $this->server->getCopyAndMoveInfo($request);

        if ($copyInfo['destinationExists']) {
            if (!$this->server->emit('beforeUnbind',[$copyInfo['destination']])) return false;
            $this->server->tree->delete($copyInfo['destination']);

        }
        if (!$this->server->emit('beforeBind',[$copyInfo['destination']])) return false;
        $this->server->tree->copy($path, $copyInfo['destination']);
        $this->server->emit('afterBind',[$copyInfo['destination']]);

        // If a resource was overwritten we should send a 204, otherwise a 201
        $response->setHeader('Content-Length','0');
        $response->setStatus($copyInfo['destinationExists']?204:201);

        // Sending back false will interupt the event chain and tell the server
        // we've handled this method.
        return false;


    }

    /**
     * HTTP REPORT method implementation
     *
     * Although the REPORT method is not part of the standard WebDAV spec (it's from rfc3253)
     * It's used in a lot of extensions, so it made sense to implement it into the core.
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return bool
     */
    public function httpReport(RequestInterface $request, ResponseInterface $response) {

        $path = $request->getPath();

        $body = $request->getBodyAsString();
        $dom = XMLUtil::loadDOMDocument($body);

        $reportName = XMLUtil::toClarkNotation($dom->firstChild);

        if ($this->server->emit('report',[$reportName, $dom, $path])) {

            // If emit returned true, it means the report was not supported
            throw new Exception\ReportNotSupported();

        }

        // Sending back false will interupt the event chain and tell the server
        // we've handled this method.
        return false;

    }

    /**
     * This method is called during property updates.
     *
     * Here we check if a user attempted to update a protected property and
     * ensure that the process fails if this is the case.
     *
     * @param string $path
     * @param PropPatch $propPatch
     * @return void
     */
    public function propPatchProtectedPropertyCheck($path, PropPatch $propPatch) {

        // Comparing the mutation list to the list of propetected properties.
        $mutations = $propPatch->getMutations();

        $protected = array_intersect(
            $this->server->protectedProperties,
            array_keys($mutations)
        );

        if ($protected) {
            $propPatch->setResultCode($protected, 403);
        }

    }

    /**
     * This method is called during property updates.
     *
     * Here we check if a node implements IProperties and let the node handle
     * updating of (some) properties.
     *
     * @param string $path
     * @param PropPatch $propPatch
     * @return void
     */
    public function propPatchNodeUpdate($path, PropPatch $propPatch) {

        // This should trigger a 404 if the node doesn't exist.
        $node = $this->server->tree->getNodeForPath($path);

        if ($node instanceof IProperties) {
            $node->propPatch($propPatch);
        }

    }

    /**
     * This method is called when properties are retrieved.
     *
     * Here we add all the default properties.
     *
     * @param PropFind $propFind
     * @param INode $node
     * @return void
     */
    public function propFind(PropFind $propFind, INode $node) {

        $propFind->handle('{DAV:}getlastmodified', function() use ($node) {
            $lm = $node->getLastModified();
            if ($lm) {
                return new Property\GetLastModified($lm);
            }
        });

        if ($node instanceof IFile) {
            $propFind->handle('{DAV:}getcontentlength', [$node, 'getSize']);
            $propFind->handle('{DAV:}getetag', [$node, 'getETag']);
            $propFind->handle('{DAV:}getcontenttype', [$node, 'getContentType']);
        }

        if ($node instanceof IQuota) {
            $quotaInfo = null;
            $propFind->handle('{DAV:}quota-used-bytes', function() use (&$quotaInfo, $node) {
                $quotaInfo = $node->getQuotaInfo();
                return $quotaInfo[0];
            });
            $propFind->handle('{DAV:}quota-available-bytes', function() use (&$quotaInfo, $node) {
                if (!$quotaInfo) {
                    $quotaInfo = $node->getQuotaInfo();
                }
                return $quotaInfo[1];
            });
        }

        $propFind->handle('{DAV:}supported-report-set', function() use ($propFind) {
            $reports = [];
            foreach($this->server->getPlugins() as $plugin) {
                $reports = array_merge($reports, $plugin->getSupportedReportSet($propFind->getPath()));
            }
            return new Property\SupportedReportSet($reports);
        });
        $propFind->handle('{DAV:}resourcetype', function() use ($node) {
            return new Property\ResourceType($this->server->getResourceTypeForNode($node));
        });
        $propFind->handle('{DAV:}supported-method-set', function() use ($propFind) {
            return new Property\SupportedMethodSet(
                $this->server->getAllowedMethods($propFind->getPath())
            );
        });

    }

    /**
     * Fetches properties for a node.
     *
     * This event is called a bit later, so plugins have a chance first to
     * populate the result.
     *
     * @param PropFind $propFind
     * @param INode $node
     * @return void
     */
    public function propFindNode(PropFind $propFind, INode $node) {

        if ($node instanceof IProperties && $propertyNames = $propFind->get404Properties()) {

            $nodeProperties = $node->getProperties($propertyNames);

            foreach($nodeProperties as $propertyName=>$value) {
                $propFind->set($propertyName, $value, 200);
            }

        }

    }

}
