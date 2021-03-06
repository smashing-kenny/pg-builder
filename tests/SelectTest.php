<?php
/**
 * Query builder for PostgreSQL backed by a query parser
 *
 * LICENSE
 *
 * This source file is subject to BSD 2-Clause License that is bundled
 * with this package in the file LICENSE and available at the URL
 * https://raw.githubusercontent.com/sad-spirit/pg-builder/master/LICENSE
 *
 * @package   sad_spirit\pg_builder
 * @copyright 2014-2018 Alexey Borzov
 * @author    Alexey Borzov <avb@php.net>
 * @license   http://opensource.org/licenses/BSD-2-Clause BSD 2-Clause license
 * @link      https://github.com/sad-spirit/pg-builder
 */

namespace sad_spirit\pg_builder\tests;

use sad_spirit\pg_builder\Lexer,
    sad_spirit\pg_builder\Parser,
    sad_spirit\pg_builder\SetOpSelect,
    sad_spirit\pg_builder\Select,
    sad_spirit\pg_builder\SqlBuilderWalker;

/**
 * Tests helper methods of Select node
 */
class SelectTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Parser
     */
    protected $parser;
    /**
     * @var SqlBuilderWalker
     */
    protected $builder;

    public function setUp()
    {
        $this->parser  = new Parser(new Lexer());
        $this->builder = new SqlBuilderWalker(array(
            'indent'    => '',
            'linebreak' => '',
            'wrap'      => null
        ));
    }

    public function testSimpleUnion()
    {
        $select = $this->parser->parseSelectStatement('select * from foo');
        $select->setParser($this->parser);
        $setOp = $select->union('select * from bar', false);
        $this->assertAttributeSame($this->parser, '_parser', $setOp);
        $this->assertEquals(
            'select * from foo union all select * from bar',
            $setOp->dispatch($this->builder)
        );
    }

    public function testNestedSetOp()
    {
        /* @var $setOp SetOpSelect */
        $setOp = $this->parser->parseSelectStatement('select * from foo intersect select * from bar');
        $setOp->right->except($this->parser->parseSelectStatement('select * from baz'));
        $this->assertEquals(
            'select * from foo intersect (select * from bar except select * from baz)',
            $setOp->dispatch($this->builder)
        );
    }

    public function testRangeSubselectSetOp()
    {
        /* @var $select Select */
        $select = $this->parser->parseSelectStatement('select foo.* from (select * from foosource) as foo');
        $select->setParser($this->parser);
        $select->from[0]->query->intersect('select * from barsource');
        $this->assertEquals(
            'select foo.* from (select * from foosource intersect select * from barsource) as foo',
            $select->dispatch($this->builder)
        );
    }

    public function testScalarSubselectSetOp()
    {
        /* @var $select Select */
        $select = $this->parser->parseSelectStatement(
            'select * from foo where foo_id in (select id from bar) or foo_name > any(select baz_name from baz)'
        );
        $select->setParser($this->parser);
        /* @var $in \sad_spirit\pg_builder\nodes\expressions\InExpression */
        $in = $select->where->condition[0];
        $in->right->union('select id from quux');
        /* @var $any \sad_spirit\pg_builder\nodes\expressions\SubselectExpression */
        $any = $select->where->condition[1]->right;
        $any->query->except('select xyzzy_name from xyzzy');

        $this->assertEquals(
            'select * from foo where foo_id in (select id from bar union select id from quux)'
            . ' or foo_name > any(select baz_name from baz except select xyzzy_name from xyzzy)',
            $select->dispatch($this->builder)
        );
    }
}