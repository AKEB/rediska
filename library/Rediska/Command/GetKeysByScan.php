<?php

/**
 * Returns all the keys matching the glob-style pattern
 * Glob style patterns examples:
 *   h?llo will match hello hallo hhllo
 *   h*llo will match hllo heeeello
 *   h[ae]llo will match hello and hallo, but not hillo
 * 
 * @author Ivan Shumkov
 * @package Rediska
 * @subpackage Commands
 * @version @package_version@
 * @link http://rediska.geometria-lab.net
 * @license http://www.opensource.org/licenses/bsd-license.php
 */
class Rediska_Command_GetKeysByScan extends Rediska_Command_Abstract
{
	/**
	* Create command
	*
	* @param string $pattern Pattern
	* @return Rediska_Connection_Exec
	*/
	public function create($cursor, $pattern, $count) {
		$commands = array();
		$command = array(
			'SCAN',
			$cursor
		);
		if ($pattern) {
			$command[] = 'MATCH';
			$command[] = $this->_rediska->getOption('namespace') . $pattern;
		}
		if ($count) {
			$command[] = 'COUNT';
			$command[] = $count;
		}
		foreach($this->_rediska->getConnections() as $connection) {
			$commands[] = new Rediska_Connection_Exec($connection, $command);
		}

		return $commands;
	}

    /**
     * Parse responses
     *
     * @param array $responses
     * @return array
     */
    public function parseResponses($responses)
    {
        $data = array();
        foreach($responses as $response) {
            $keys = array();
            if (!empty($response)) {
                $keys = is_array($response) ? $response : explode(' ', $response);
                $keys = array_unique($keys);
                
                if ($this->_rediska->getOption('namespace') != '') {
                   $namespaceLength = strlen($this->_rediska->getOption('namespace'));
                   foreach($keys as &$key) {
                      if (strpos($key, $this->_rediska->getOption('namespace')) === 0) {
                         $key = substr($key, $namespaceLength);
                      }
                   }
                }
            }
            $data[] = $keys;
        }
        
        $new_data = array();
        foreach ($data as $v) {
        		foreach ($v as $key) $new_data[] = $key;
        }
        unset($data);
        return $new_data;
    }
}