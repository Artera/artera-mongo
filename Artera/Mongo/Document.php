<?php
/**
 * @category   Artera
 * @package    Artera_Mongo
 * @copyright  Artera S.r.l.
 * @license    New BSD License
 * @author     Massimiliano Torromeo
 */
class Artera_Mongo_Document implements ArrayAccess, Countable {
	protected $_data = array();
	protected $_newdata = array();
	protected $_unsetdata = array();
	public $collection = null;
	protected $_reference = null;
	public $parent = false;

	public function __construct($data=array(), $parent=false, $collection=null) {
		if (!is_array($data))
			throw new Artera_Mongo_Exception('Invalid data provided to the document. $data is not an array.');

		if (is_null($collection))
			$this->collection = Artera_Mongo::documentCollection(get_class($this));
		elseif ($collection instanceof Artera_Mongo_Collection)
			$this->collection = $collection;
		else
			$this->collection = Artera_Mongo::defaultDB()->selectCollection($collection);

		$this->_data = $data;
		foreach ($this->_data as $key => $data) {
			$this->_data[$key] = Artera_Mongo::documentOrSet($data, $this->collection->getName().".$key");
			if ($this->_data[$key] instanceof Artera_Mongo_Document || $this->_data[$key] instanceof Artera_Mongo_Document_Set)
				$this->_data[$key]->parent = $this;
		}
		if ($parent !== false && !$parent instanceof Artera_Mongo_Document && !$parent instanceof Artera_Mongo_Document_Set)
			throw new Artera_Mongo_Exception('Invalid parent. Parent must be one of false, Artera_Mongo_Document or Artera_Mongo_Document_Set');
		$this->parent = $parent;
	}

	public static function indexes() {
		return isset(static::$_indexes) ? static::$_indexes : array();
	}

	public function parentDocument() {
		$parent = $this->parent;
		while ($parent != false && !($parent instanceof Artera_Mongo_Document))
			$parent = $parent->parent;
		return $parent;
	}

	public static function find($query=array(), $fields=array()) {
		if (is_string($query)) $query = new MongoId($query);
		if ($query instanceof MongoId) $query = array('_id' => $query);
		$coll = Artera_Mongo::documentCollection(get_called_class());
		return $coll->find($query, $fields);
	}

	public static function findOne($query=array(), $fields=array()) {
		if (is_string($query)) $query = new MongoId($query);
		if ($query instanceof MongoId) $query = array('_id' => $query);
		$coll = Artera_Mongo::documentCollection(get_called_class());
		return $coll->findOne($query, $fields);
	}

	public function reference() {
		if ($this->isReference())
			return $this->_reference;
		return $this->collection->createDBRef($this->_data(false));
	}

	public function isReference() {
		return !is_null($this->_reference);
	}

	public function setReference($reference) {
		$this->_reference = $reference;
	}

	public function getDBRef($reference) {
		$doc = $this->collection->getDBRef($reference);
		$doc->parent = $this;
		return $doc;
	}

	public function __isset($name) {
		return array_key_exists($name, $this->_newdata) || (array_key_exists($name, $this->_data) && !in_array($name, $this->_unsetdata));
	}

	public function __get($name) {
		$value = null;
		if (array_key_exists($name, $this->_newdata))
			$value = $this->_newdata[$name];
		elseif (array_key_exists($name, $this->_data) && !in_array($name, $this->_unsetdata))
			$value = $this->_data[$name];
		//Resolve reference
		if (MongoDBRef::isRef($value))
			return $this->getDBRef($value);
		else
			return $value;
	}

	public function __set($name, $value) {
		if (strpos($name, '.') !== false)
			throw new Artera_Mongo_Exception("The '.' character must not appear anywhere in the key name.");
		if (strlen($name)>0 && $name[0]=='$')
			throw new Artera_Mongo_Exception("The '$' character must not be the first character in the key name.");
		if (is_null($value)) {
			if (array_key_exists($name, $this->_data))
				$this->_unsetdata[] = $name;
			if (array_key_exists($name, $this->_newdata))
				unset($this->_newdata[$name]);
		} else {
			$this->_newdata[$name] = Artera_Mongo::documentOrSet($value, $this->collection->getName().".$name");
			if ($this->_newdata[$name] instanceof Artera_Mongo_Document || $this->_newdata[$name] instanceof Artera_Mongo_Document_Set)
				$this->_newdata[$name]->parent = $this;
		}
	}

	public function setData(array $data) {
		foreach ($data as $name => $value)
			$this->__set($name, $value);
		return $this;
	}

	public function remove($query=null) {
		if (is_null($query) && !isset($this))
			throw new Artera_Mongo_Exception('The remove method cannot be called statically without parameters. If you really want to remove every document in the collection call Artera_Mongo_Document::remove(array());');

		if (is_null($query)) {
			$this->collection->remove(array('_id' => $this->_id));
		} else {
			$collection = isset($this) ? $this->collection : Artera_Mongo::documentCollection(get_called_class());
			$collection->remove($query);
		}

		return $this;
	}

	public function count() { return count($this->_data(false)); }
	public function offsetSet($offset, $value) { return $this->__set($offset, $value); }
	public function offsetExists($offset) { return $this->__isset($offset); }
	public function offsetUnset($offset) { $this->__set($offset, null); }
	public function offsetGet($offset) { return $this->__get($offset); }

	public function modified() {
		return count($this->_newdata) || count($this->_unsetdata);
	}

	public function data($translate = true) {
		$data = array_merge($this->_data, $this->_newdata);
		foreach ($this->_unsetdata as $name)
			unset($data[$name]);
		if ($translate)
			foreach ($data as $i => $v) {
				if ($v instanceof Artera_Mongo_Document_Set) {
					$v = $v->savedata(true);
				} elseif ($v instanceof Artera_Mongo_Document) {
					$v = $v->savedata();
				}
				$data[$i] = $v;
			}
		return $data;
	}

	public function savedata() {
		if ($this->isReference())
			return $this->_reference;
		else
			return $this->_data();
	}

	public function save() {
		if (!$this->isReference() && $this->parent && !array_key_exists('_id', $this->_data)) {
			$root = $this;
			while ($root->parent !== false)
				$root = $root->parent;
			if ($root instanceof Artera_Mongo_Document_Set)
				throw new Artera_Mongo_Exception('Invalid Document_Set. A Document_Set must have a parent.');
			return $root->save();
		}

		Artera_Mongo::checkConnection();

		$data = $this->_data(false);

		if (array_key_exists('_id', $this->_data)) {
			$update = array();
			if (count($this->_newdata)) {
				$update['$set'] = array();
				foreach ($this->_newdata as $field => $newdata) {
					if ($newdata instanceof Artera_Mongo_Document_Set || $newdata instanceof Artera_Mongo_Document)
						$newdata = $newdata->savedata();
					$update['$set'][$field] = $newdata;
				}
			}
			foreach ($data as $field => $olddata) {
				if (!isset($update['$set'][$field])) {
					if ($olddata instanceof Artera_Mongo_Document_Set) {
						$olddata = $olddata->savedata();
						if (!is_null($olddata))
							$update['$set'][$field] = $olddata;
					} elseif ($olddata instanceof Artera_Mongo_Document && $olddata->modified() && !$olddata->isReference()) {
						$update['$set'][$field] = $olddata->savedata();
					}
				}
			}
			if (count($this->_unsetdata)) {
				$update['$unset'] = array();
				foreach($this->_unsetdata as $name)
					$update['$unset'][$name] = 1;
			}
			if (!empty($update))
				$this->collection->update( array('_id' => $this->_id), $update );
		} else {
			$insdata = $this->_data();
			$this->collection->insert($insdata);
			$data['_id'] = $insdata['_id'];
		}

		$this->_data = $data;
		$this->_newdata = array();
		$this->_unsetdata = array();
	}
}