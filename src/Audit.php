<?php
/**
 * @link https://github.com/tigrov/yii2-pgsql-audit
 * @author Sergei Tigrov <rrr-r@ya.ru>
 */

namespace tigrov\pgsql\audit;

use yii\db\ActiveQuery;
use yii\db\Exception;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;

/**
 * This is the model class for table "audit".
 *
 * @property integer $id
 * @property string $model_class
 * @property integer $pk_value
 * @property integer $user_id
 * @property \DateTime $created_at
 * @property string $type_key
 * @property array $old_values
 * @property array $new_values
 *
 * @property IdentityInterface $user
 * @property ActiveRecord $model
 *
 * @author Sergei Tigrov <rrr-r@ya.ru>
 */
class Audit extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%audit}}';
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'type' => AuditType::className(),
        ];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['old_values', 'new_values'], 'safe'],
            [['model_class', 'pk_value', 'type_key'], 'required'],
            [['model_class', 'type_key'], 'string'],
            [['pk_value', 'user_id'], 'integer'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function transactions()
    {
        return [static::SCENARIO_DEFAULT => static::OP_INSERT];
    }

    /**
     * @inheritdoc
     */
    public function save($runValidation = true, $attributeNames = null)
    {
        try {
            return parent::save($runValidation, $attributeNames);
        } catch (Exception $e) {
            ModelClass::add($this->model_class);
            return parent::save($runValidation, $attributeNames);
        }
    }

    /**
     * Get the user identity
     *
     * @return IdentityInterface
     */
    public function getUser()
    {
        if (!\Yii::$app->has('user') || !$this->user_id) {
            return null;
        }

        /** @var IdentityInterface $identityClass */
        $identityClass = \Yii::$app->getUser()->identityClass;
        return $identityClass::findIdentity($this->user_id);
    }

    /**
     * Get the model object
     *
     * @return ActiveRecord
     */
    public function getModel()
    {
        /** @var ActiveRecord $modelClass */
        $modelClass = $this->model_class;
        return $modelClass::findOne($this->pk_value)
            // create new model with the same primary key if the model was deleted
            ?: new $modelClass([
                $modelClass::primaryKey()[0] => $this->pk_value
            ]);
    }

    /**
     * Find audit records by model
     *
     * @param ActiveRecord $model ActiveRecord model
     * @return ActiveQuery
     */
    public static function findByModel(ActiveRecord $model)
    {
        return Audit::find()->where([
            'model_class' => $model::className(),
            'pk_value' => $model->getPrimaryKey(),
        ]);
    }

    /**
     * Find audit records by user ID
     *
     * @param int $userId user ID
     * @return ActiveQuery
     */
    public static function findByUserId($userId)
    {
        return static::find()->where(['user_id' => $userId]);
    }

    /**
     * Revert to the current audit version
     *
     * @return null|ActiveRecord reverted model
     */
    public function revert()
    {
        $model = $this->model;
        if ($model->getIsNewRecord()) {
            // To init values of excepted attributes
            $model->loadDefaultValues();
        }
        $requiringUpdates = $model->filterAuditableValues($model->getAttributes());

        $query = static::findByModel($model);
        $query->select(['old_values'])
            ->andWhere(['>=', 'id', $this->id])
            ->andWhere(['!=', 'type_key', 'insert'])
            ->orderBy(['id' => SORT_ASC]);

        foreach ($query->each() as $audit) {
            if (!$requiringUpdates) {
                break;
            }

            $values = array_intersect_key($audit->old_values, $requiringUpdates);
            \Yii::configure($model, $values);

            $requiringUpdates = array_diff_key($requiringUpdates, $audit->old_values);
        }

        return $model->save(false)
            ? $model
            : null;
    }
}
