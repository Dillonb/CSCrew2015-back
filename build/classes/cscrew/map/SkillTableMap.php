<?php



/**
 * This class defines the structure of the 'skill' table.
 *
 *
 *
 * This map class is used by Propel to do runtime db structure discovery.
 * For example, the createSelectSql() method checks the type of a given column used in an
 * ORDER BY clause to know whether it needs to apply SQL to make the ORDER BY case-insensitive
 * (i.e. if it's a text column type).
 *
 * @package    propel.generator.cscrew.map
 */
class SkillTableMap extends TableMap
{

    /**
     * The (dot-path) name of this class
     */
    const CLASS_NAME = 'cscrew.map.SkillTableMap';

    /**
     * Initialize the table attributes, columns and validators
     * Relations are not initialized by this method since they are lazy loaded
     *
     * @return void
     * @throws PropelException
     */
    public function initialize()
    {
        // attributes
        $this->setName('skill');
        $this->setPhpName('Skill');
        $this->setClassname('Skill');
        $this->setPackage('cscrew');
        $this->setUseIdGenerator(true);
        // columns
        $this->addPrimaryKey('id', 'Id', 'INTEGER', true, null, null);
        $this->addColumn('name', 'Name', 'LONGVARCHAR', false, null, null);
        // validators
    } // initialize()

    /**
     * Build the RelationMap objects for this table relationships
     */
    public function buildRelations()
    {
        $this->addRelation('UserSkill', 'UserSkill', RelationMap::ONE_TO_MANY, array('id' => 'skill_id', ), null, null, 'UserSkills');
        $this->addRelation('User', 'User', RelationMap::MANY_TO_MANY, array(), null, null, 'Users');
    } // buildRelations()

} // SkillTableMap
