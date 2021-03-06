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

namespace sad_spirit\pg_builder\nodes\range;

use sad_spirit\pg_builder\Node,
    sad_spirit\pg_builder\nodes\QualifiedName,
    sad_spirit\pg_builder\TreeWalker;

/**
 * AST node for relation (table or view) reference in FROM clause
 *
 * @property-read QualifiedName $name
 * @property-read bool|null     $inherit
 */
class RelationReference extends FromElement
{
    public function __construct(QualifiedName $qualifiedName, $inheritOption = null)
    {
        $this->setNamedProperty('name', $qualifiedName);
        $this->props['inherit'] = null === $inheritOption ? null : (bool)$inheritOption;
    }

    public function dispatch(TreeWalker $walker)
    {
        return $walker->walkRelationReference($this);
    }

    /**
     * Checks in base setParentNode() are redundant as this can only contain a QualifiedName instance
     *
     * @param Node $parent
     */
    protected function setParentNode(Node $parent = null)
    {
        if ($parent && $this->parentNode && $parent !== $this->parentNode) {
            $this->parentNode->removeChild($this);
        }
        $this->parentNode = $parent;
    }
}
