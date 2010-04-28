<?php

class SuperFindBehavior extends ModelBehavior {

	function setup(&$model, $config = array()) {
	}

	function superFind(&$Model, $conditions = null, $fields = array(), $order = null, $recursive = null) {
		if (!is_string($conditions)) {
			$type = 'first';
			$query = array_merge(compact('conditions', 'fields', 'order', 'recursive'), array('limit' => 1));
		} else {
			list($type, $query) = array($conditions, $fields);
		}

		$relations = array('belongsTo', 'hasOne', 'hasMany', 'hasAndBelongsToMany');
		$originalRelations = array();
		foreach ($relations as $relation) {
			$originalRelations[$relation] = $Model->$relation;
		}
		if (isset($query['conditions'])) {
			if (!is_array($query['conditions'])) {
				$query['conditions'] = (array)$query['conditions'];
			}
			$extraFinds = array(
				'hasMany' => array(),
				'HABTM' => array()
			);
			foreach ($query['conditions'] as $key => $value) {
				$originalKey = $key;
				$check =& $value;
				if (is_string($key)) {
					$check =& $key;
				}
				if (!preg_match('/^\w+\.\w+(\.\w+)*/', $check, $matches)) {
					continue;
				}
				$modelsName = explode('.', $matches[0]);
				array_pop($matches); // Remove the field name
				if ($modelsName[0] === $Model->alias) {
					if (count($modelsName) > 1) {
						$check = substr($check, strlen($modelsName[0]) + 1);
						unset($modelsName[0]);
					} else {
						continue;
					}
				}
				$mainModel = reset($modelsName);
				if ($mainModel === $Model->alias || isset($Model->belongsTo[$mainModel]) || isset($Model->hasOne[$mainModel])) {
					continue;
				}
				if (isset($Model->hasMany[$mainModel], $Model->$mainModel)) {
					$extraFinds['hasMany'][$mainModel][$key] = $value;
					unset($query['conditions'][$originalKey]);
				} elseif (isset($Model->hasAndBelongsToMany[$mainModel], $Model->$mainModel)) {
					$extraFinds['HABTM'][$mainModel][$key] = $value;
					unset($query['conditions'][$originalKey]);
				}
			}
			foreach ($extraFinds['hasMany'] as $modelName => $extraConditions) {
				$fk = $Model->hasMany[$modelName]['foreignKey'];
				$pk = $modelName . '.' . $Model->$modelName->primaryKey;
				$removeBehavior = false;
				if (!$Model->$modelName->Behaviors->attached('SuperFind.SuperFind')) {
					$removeBehavior = true;
					$Model->$modelName->Behaviors->attach('SuperFind.SuperFind');
				}
				$data = $Model->$modelName->superFind('all', array(
					'fields' => array($fk, $pk),
					'conditions' => $extraConditions,
					'recursive' => -1
				));
				if ($removeBehavior) {
					$Model->$modelName->Behaviors->detach('SuperFind.SuperFind');
				}
				$masterModelIds = array_unique(Set::extract('{n}.' . $modelName . '.' . $fk, $data));
				if (empty($masterModelIds)) {
					$query['conditions'] = '1 = 0';
					break;
				}
				$query['conditions'][$Model->primaryKey] = $masterModelIds;
				$otherModelIds = array_unique(Set::extract('{n}.' . $pk, $data));
				$this->_addCondition($Model, 'hasMany', $modelName, $pk, $otherModelIds);
			}
			foreach ($extraFinds['HABTM'] as $modelName => $extraConditions) {
				$pk = $modelName . '.' . $Model->$modelName->primaryKey;
				if (!$Model->$modelName->Behaviors->attached('SuperFind.SuperFind')) {
					$removeBehavior = true;
					$Model->$modelName->Behaviors->attach('SuperFind.SuperFind');
				}
				$data = $Model->$modelName->superFind('all', array(
					'fields' => array($pk),
					'conditions' => $extraConditions,
					'recursive' => -1
				));
				if ($removeBehavior) {
					$Model->$modelName->Behaviors->detach('SuperFind.SuperFind');
				}
				if (empty($data)) {
					$query['conditions'] = '1 = 0';
					break;
				}
				$otherModelIds = array_unique(Set::extract('{n}.' . $pk, $data));

				$relationModel = new Model(array(
					'table' => $Model->hasAndBelongsToMany[$modelName]['joinTable'],
					'ds' => $Model->useDbConfig,
					'name' => 'Relation'
				));
				$relationModel->Behaviors->attach('SuperFind.SuperFind');
				$data = $relationModel->superFind('all', array(
					'fields' => array($Model->hasAndBelongsToMany[$modelName]['foreignKey']),
					'conditions' => array($Model->hasAndBelongsToMany[$modelName]['associationForeignKey'] => $otherModelIds)
				));
				unset($relationModel);
				if (empty($data)) {
					$query['conditions'] = '1 = 0';
					break;
				}
				$masterModelIds = array_unique(Set::extract('{n}.Relation.' . $Model->hasAndBelongsToMany[$modelName]['foreignKey'], $data));

				$query['conditions'][$Model->primaryKey] = $masterModelIds;
				$this->_addCondition($Model, 'hasAndBelongsToMany', $modelName, $pk, $otherModelIds);
			}
		}

		$return = $Model->find($type, $query);
		foreach ($relations as $relation) {
			$Model->$relation = $originalRelations[$relation];
		}

		return $return;
	}

	function _addCondition(&$Model, $type, $otherModel, $field, $value) {
		$relation =& $Model->$type;
		if (!empty($relation[$otherModel]['conditions'])) {
			$relation[$otherModel]['conditions'] = array($relation[$otherModel]['conditions']);
		}
		if (isset($relation[$otherModel]['conditions'][$field])) {
			if (!is_array($relation[$otherModel]['conditions'][$field])) {
				$relation[$otherModel]['conditions'][$field] = array($relation[$otherModel]['conditions'][$field]);
			}
		} else {
			$relation[$otherModel]['conditions'][$field] = array();
		}
		$relation[$otherModel]['conditions'][$field] = array_merge($relation[$otherModel]['conditions'][$field], $value);
	}
}

?>