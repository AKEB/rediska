<?php

// Require Rediska
require_once dirname(__FILE__) . '/../../../../Rediska.php';

/**
 * @see Zend_Cache_Backend
 */
require_once 'Zend/Cache/Backend.php';

/**
 * @see Zend_Cache_Backend_ExtendedInterface
 */
require_once 'Zend/Cache/Backend/ExtendedInterface.php';

/**
 * Redis adapter for Zend_Cache
 *
 * @author Ivan Shumkov
 * @package Rediska
 * @subpackage ZendFrameworkIntegration
 * @version @package_version@
 * @link http://rediska.geometria-lab.net
 * @license http://www.opensource.org/licenses/bsd-license.php
 */
class Rediska_Zend_Cache_Backend_Redis extends Zend_Cache_Backend implements Zend_Cache_Backend_ExtendedInterface
{
    const SET_IDS         = 'zc:ids';
    const SET_TAGS        = 'zc:tags';
    const PREFIX_KEY      = 'zc:k:';
    const PREFIX_TAG_IDS  = 'zc:ti:';
    const FIELD_DATA      = 'd';
    const FIELD_MTIME     = 'm';
    const FIELD_TAGS      = 't';
    const FIELD_INF       = 'i';

    /**
     *  Redis backend limit
     * @var integer
     */
    const MAX_LIFETIME    = 2592000;
    /**
     * Rediska instance
     *
     * @var Rediska
     */
    protected $_rediska = Rediska::DEFAULT_NAME;
    /**
     *
     * @var Rediska_Transaction
     */
    protected $_transaction;

    /**
     * Contruct Zend_Cache Redis backend
     *
     * @param mixed $rediska Rediska instance name, Rediska object or array of options
     */
    public function __construct($options = array())
    {
        if ($options instanceof Zend_Config) {
            $options = $options->toArray();
        }
        if (isset($options['rediska'])) {
            $this->setRediska($options['rediska']);
        }
    }

    public function setRediska($rediska)
    {
        $this->_rediska = $rediska;
        return $this;
    }

    public function getRediska()
    {
        if (!is_object($this->_rediska)) {
            $this->_rediska = Rediska_Options_RediskaInstance::getRediskaInstance(
                $this->_rediska, 'Zend_Cache_Exception', 'backend'
            );
        }

        return $this->_rediska;
    }
    /**
     *
     * @param string $alias
     * @return Rediska_Zend_Cache_Backend_Redis
     */
    protected function getTransaction()
    {
        if(!$this->_transaction instanceof Rediska_Transaction){
            $this->_transaction = $this->getRediska()->transaction();
        }
        return $this->_transaction;
    }
    protected function resetTransaction()
    {
        $this->_transaction = null;
        return $this;
    }
    /**
     * Load value with given id from cache
     *
     * @param  string  $id                     Cache id
     * @param  boolean $doNotTestCacheValidity If set to true, the cache validity won't be tested
     * @return string|false cached datas
     */
    public function load($id, $doNotTestCacheValidity = false)
    {
        if(!$this->getRediska()->exists(self::PREFIX_KEY.$id)){
            return false;
        }
        return $this->getRediska()->getFromHash(
            self::PREFIX_KEY.$id, self::FIELD_DATA
        );
    }

    /**
     * Test if a cache is available or not (for the given id)
     *
     * @param  string $id Cache id
     * @return mixed|false (a cache is not available) or "last modified" timestamp (int) of the available cache record
     */
    public function test($id)
    {
        $mtime = $this->getRediska()->getFromHash(
            self::PREFIX_KEY.$id, self::FIELD_MTIME
        );
        return ($mtime ? $mtime : false);
    }

    /**
     * Save some string datas into a cache record
     *
     * Note : $data is always "string" (serialization is done by the
     * core not by the backend)
     *
     * @param  string $data             Datas to cache
     * @param  string $id               Cache id
     * @param  array  $tags             Array of strings, the cache record will be tagged by each string entry
     * @param  int    $specificLifetime If != false, set a specific lifetime for this cache record (null => infinite lifetime)
     * @return boolean True if no problem
     */
    public function save($data, $id, $tags = array(), $specificLifetime = false)
    {
        if(!is_array($tags)) $tags = array($tags);

        $lifetime = $this->getLifetime($specificLifetime);

        $oldTags = explode(
            ',', $this->getRediska()->getFromHash(
                self::PREFIX_KEY.$id, self::FIELD_TAGS
            )
        );
        $transaction = $this->getTransaction();
        $transaction->setToHash(
            self::PREFIX_KEY.$id,  array(
            self::FIELD_DATA => $data,
            self::FIELD_TAGS => implode(',',$tags),
            self::FIELD_MTIME => time(),
            self::FIELD_INF => $lifetime ? 0 : 1)
        );
        $transaction->expire(self::PREFIX_KEY.$id, $lifetime ? $lifetime : self::MAX_LIFETIME);
        if ($addTags = ($oldTags ? array_diff($tags, $oldTags) : $tags)) {
            $transaction->addToSet( self::SET_TAGS, $addTags);
            foreach($addTags as $tag){
                $transaction->addToSet(self::PREFIX_TAG_IDS . $tag, $id);
            }
        }
        if ($remTags = ($oldTags ? array_diff($oldTags, $tags) : false)){
            foreach($remTags as $tag){
                $transaction->deleteFromSet(self::PREFIX_TAG_IDS . $tag, $id);
            }
        }
        $transaction->addToSet(self::SET_IDS, $id);

        $transaction->execute();

        return true;
    }

    /**
     * Remove a cache record
     *
     * @param  string $id Cache id
     * @return boolean True if no problem
     */
    public function remove($id)
    {
        $tags = explode(
            ',', $this->getRediska()->getFromHash(
                self::PREFIX_KEY.$id, self::FIELD_TAGS
            )
        );

        $transaction = $this->getTransaction();
        $transaction->delete(self::PREFIX_KEY.$id);
        $transaction->deleteFromSet( self::SET_IDS, $id );
        foreach($tags as $tag) {
            $transaction->deleteFromSet(self::PREFIX_TAG_IDS . $tag, $id);
        }
        return (bool) $transaction->execute();
    }

    /**
     * Clean some cache records
     *
     * Available modes are :
     * 'all' (default)  => remove all cache entries ($tags is not used)
     * 'old'            => supported
     * 'matchingTag'    => supported
     * 'notMatchingTag' => supported
     * 'matchingAnyTag' => supported
     *
     * @param  string $mode Clean mode
     * @param  array  $tags Array of tags
     * @throws Zend_Cache_Exception
     * @return boolean True if no problem
     */
    public function clean($mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array())
    {
        if( $tags && ! is_array($tags)) {
            $tags = array($tags);
        }
        if($mode == Zend_Cache::CLEANING_MODE_ALL) {
            $ids = $this->getIds();
            $this->removeIds($ids);
        }
        if($mode == Zend_Cache::CLEANING_MODE_OLD) {
            $this->_collectGarbage();
            return true;
        }
        if( ! count($tags)) {
            return true;
        }
        $result = true;
        switch ($mode) {
            case Zend_Cache::CLEANING_MODE_MATCHING_TAG:
                $this->removeIdsByMatchingTags($tags);
                break;

            case Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:
                $this->removeIdsByNotMatchingTags($tags);
                break;

            case Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:
                $this->removeIdsByMatchingAnyTags($tags);
                break;

            default:
                Zend_Cache::throwException('Invalid mode for clean() method: '.$mode);
        }
        return $this->_collectGarbage();
    }
    protected function removeIds($ids = array())
    {
        $transaction = $this->getTransaction();
        $transaction->delete($this->_preprocessIds($ids));
        foreach($ids as $id){
            $transaction->deleteFromSet( self::SET_IDS, $id);
        }
        return (bool) $transaction->execute();
    }

    /**
     * @param array $tags
     */
    protected function removeIdsByNotMatchingTags($tags)
    {
        $transaction = $this->getTransaction();
        $ids = $this->getIdsNotMatchingTags($tags);
        $this->removeIds($ids);
    }
    /**
     * @param array $tags
     */
    protected function removeIdsByMatchingTags($tags)
    {
        $transaction = $this->getTransaction();
        $ids = $this->getIdsMatchingTags($tags);
        $this->removeIds($ids);
    }

    /**
     * @param array $tags
     */
    protected function removeIdsByMatchingAnyTags($tags)
    {
        $transaction = $this->getTransaction();
        $ids = $this->getIdsMatchingAnyTags($tags);
        $this->removeIds($ids);
        $transaction->delete( $this->_preprocessTagIds($tags));
        foreach($tags as $tag){
            $transaction->deleteFromSet( self::SET_TAGS, $tag);
        }
    }
    /**
     * Return true if the automatic cleaning is available for the backend
     *
     * @return boolean
     */
    public function isAutomaticCleaningAvailable()
    {
        return true;
    }

    /**
     * Set the frontend directives
     *
     * @param  array $directives Assoc of directives
     * @throws Zend_Cache_Exception
     * @return void
     */
    public function setDirectives($directives)
    {
        parent::setDirectives($directives);
        $lifetime = $this->getLifetime(false);
        if ($lifetime > self::MAX_LIFETIME) {
            $this->_log('redis backend has a limit of 30 days (2592000 seconds) for the lifetime');
        }
    }

    /**
     * Return an array of stored cache ids
     *
     * @return array array of stored cache ids (string)
     */
    public function getIds()
    {
        return (array) $this->getRediska()->getSet(self::SET_IDS);
    }

    /**
     * Return an array of stored tags
     *
     * @return array array of stored tags (string)
     */
    public function getTags()
    {
        return $this->_processResult($this->getRediska()->getSet(self::SET_TAGS));
    }

    /**
     * Return an array of stored cache ids which match given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of matching cache ids (string)
     */
    public function getIdsMatchingTags($tags = array())
    {
        return (array) $this->getRediska()->intersectSets(
            $this->_preprocessTagIds($tags)
        );
    }

    /**
     * Return an array of stored cache ids which don't match given tags
     *
     * In case of multiple tags, a logical OR is made between tags
     *
     * @param array $tags array of tags
     * @return array array of not matching cache ids (string)
     */
    public function getIdsNotMatchingTags($tags = array())
    {
            $sets = $this->_preprocessTagIds($tags);
            array_unshift($sets, self::SET_IDS);
            $data = $this->getRediska()->diffSets($sets);
            return $data;
    }

    /**
     * Return an array of stored cache ids which match any given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of any matching cache ids (string)
     */
    public function getIdsMatchingAnyTags($tags = array())
    {
        return (array) $this->getRediska()->unionSets($this->_preprocessTagIds($tags));
    }

    /**
     * Return the filling percentage of the backend storage
     *
     * @throws Zend_Cache_Exception
     * @return int integer between 0 and 100
     */
    public function getFillingPercentage()
    {
        $this->_log("Filling percentage not supported by the Redis backend");
        return 0;
    }

    /**
     * Return an array of metadatas for the given cache id
     *
     * The array must include these keys :
     * - expire : the expire timestamp
     * - tags : a string array of tags
     * - mtime : timestamp of last modification time
     *
     * @param string $id cache id
     * @return array array of metadatas (false if the cache id is not found)
     */
    public function getMetadatas($id)
    {
        list($data, $tags, $mtime, $infinite) = $this->getRediska()->getHashValues(self::PREFIX_KEY.$id);
        if(!$mtime) {
          return false;
        }
        $tags = explode(',', $tags);
        $expire = $infinite === '1' ? false : time() + $this->getRediska()
            ->getLifetime(self::PREFIX_KEY.$id);

        return array(
            'expire' => $expire,
            'tags'   => $tags,
            'mtime'  => $mtime,
        );
    }

    /**
     * Give (if possible) an extra lifetime to the given cache id
     *
     * @param string $id cache id
     * @param int $extraLifetime
     * @return boolean true if ok
     */
    public function touch($id, $extraLifetime)
    {
        $data = $this->getRediska()->getFromHash(
            self::PREFIX_KEY.$id, array(self::FIELD_INF)
        );
        if ($data['i'] === 0) {
            $expireAt = time() + $this->getRediska()
                ->getLifetime(self::PREFIX_KEY.$id) + $extraLifetime;
            return (bool) $this->getRediska()->expire(
                self::PREFIX_KEY.$id, $expireAt, true
            );
        }
        return false;
    }

    /**
     * Return an associative array of capabilities (booleans) of the backend
     *
     * The array must include these keys :
     * - automatic_cleaning (is automating cleaning necessary)
     * - tags (are tags supported)
     * - expired_read (is it possible to read expired cache records
     *                 (for doNotTestCacheValidity option for example))
     * - priority does the backend deal with priority when saving
     * - infinite_lifetime (is infinite lifetime can work with this backend)
     * - get_list (is it possible to get the list of cache ids and the complete list of tags)
     *
     * @return array associative of with capabilities
     */
    public function getCapabilities()
    {
        return array(
            'automatic_cleaning' => true,
            'tags'               => true,
            'expired_read'       => true,
            'priority'           => false,
            'infinite_lifetime'  => true,
            'get_list'           => true
        );
    }
    /**
     * Cleans up expired keys and list members
     * @return boolean
     */
    protected function _collectGarbage()
    {
        $exists = array();
        $tags = $this->getTags();
        $transaction = $this->getTransaction();
        foreach($tags as $tag){
            $tagMembers = $transaction->getSet(self::PREFIX_TAG_IDS . $tag);
            $transaction->watch(self::PREFIX_TAG_IDS . $tag);
            $expired = array();
            if(count($tagMembers)) {
                foreach($tagMembers as $id) {
                    if( ! isset($exists[$id])) {
                        $exists[$id] = $transaction->exists(self::PREFIX_KEY.$id);
                    }
                    if(!$exists[$id]) {
                        $expired[] = $id;
                    }
                }
                if(!count($expired)) continue;
            }

            if(!count($tagMembers) || count($expired) == count($tagMembers)) {
                $transaction->delete(self::PREFIX_TAG_IDS . $tag);
                $transaction->deleteFromSet(self::SET_TAGS, $tag);
            } else {
                $transaction->deleteFromSet( self::PREFIX_TAG_IDS . $tag, $expired);
            }
            $transaction->deleteFromSet( self::SET_IDS, $expired);
            try{
                $transaction->execute();
                return true;
            } catch (Rediska_Transaction_Exception $e){
                $this->_collectGarbage();
                return false;
            }
        }
    }
    /**
     * @param $item
     * @param $index
     * @param $prefix
     */
    protected function _preprocess(&$item, $index, $prefix)
    {
        $item = $prefix . $item;
    }

    /**
     * @param $ids
     * @return array
     */
    protected function _preprocessIds($ids)
    {
        array_walk($ids, array($this, '_preprocess'), self::PREFIX_KEY);
        return $ids;
    }
    protected function _processResult($data)
    {
        $result = array();
        foreach($data as $dat){
            foreach ($dat as $datum) {
                $result[] = $datum;
            }
        }
        return array_unique($result);
        return $result;
    }
    /**
     * @param $tags
     * @return array
     */
    protected function _preprocessTagIds($tags)
    {
        if($tags){
            array_walk($tags, array($this, '_preprocess'), self::PREFIX_TAG_IDS);
        }
        return $tags;
    }
}