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

namespace sad_spirit\pg_builder;

/**
 * A tree walker that generates SQL from abstract syntax tree
 */
class SqlBuilderWalker implements TreeWalker
{
    /**
     * Precedence for logical OR operator
     */
    const PRECEDENCE_OR             = 10;

    /**
     * Precedence for logical AND operator
     */
    const PRECEDENCE_AND            = 20;

    /**
     * Precedence for logical NOT operator
     */
    const PRECEDENCE_NOT            = 30;

    /**
     * Precedence for various "IS <something>" operators in Postgres 9.5+
     */
    const PRECEDENCE_IS             = 40;

    /**
     * Precedence for comparison operators in Postgres 9.5+
     */
    const PRECEDENCE_COMPARISON     = 50;

    /**
     * Precedence for equality operator '='
     */
    const PRECEDENCE_OLD_EQUALITY   = 40;

    /**
     * Precedence for strict inequality operators '<' and '>'
     *
     * NB: operators '>=' and '<=' are considered generic operators and actually
     * have higher precedence than this in pre-9.5 Postgres
     */
    const PRECEDENCE_OLD_INEQUALITY = 50;

    /**
     * Precedence for pattern matching operators LIKE / ILIKE / SIMILAR TO
     */
    const PRECEDENCE_PATTERN        = 60;

    /**
     * Precedence for OVERLAPS operator
     */
    const PRECEDENCE_OVERLAPS       = 70;

    /**
     * Precedence for BETWEEN operator (and its variants)
     */
    const PRECEDENCE_BETWEEN        = 80;

    /**
     * Precedence for IN operator
     */
    const PRECEDENCE_IN             = 90;

    /**
     * Precedence for generic postfix operators
     */
    const PRECEDENCE_POSTFIX_OP     = 100;

    /**
     * Precedence for generic infix and prefix operators
     */
    const PRECEDENCE_GENERIC_OP     = 110;

    /**
     * Precedence for various "IS <something>" operators
     */
    const PRECEDENCE_OLD_IS         = 120;

    /**
     * Precedence for arithmetic addition / substraction
     */
    const PRECEDENCE_ADDITION       = 130;

    /**
     * Precedence for arithmetic multiplication / division
     */
    const PRECEDENCE_MULTIPLICATION = 140;

    /**
     * Precedence for exponentiation operator '^'
     *
     * Note that it is left-associative, contrary to usual mathematical rules
     */
    const PRECEDENCE_EXPONENTIATION = 150;

    /**
     * Precedence for AT TIME ZONE expression
     */
    const PRECEDENCE_TIME_ZONE      = 160;

    /**
     * Precedence for COLLATE expression
     */
    const PRECEDENCE_COLLATE        = 170;

    /**
     * Precedence for unary plus / minus
     */
    const PRECEDENCE_UNARY_MINUS    = 180;

    /**
     * Precedence for PostgreSQL's typecast operator '::'
     */
    const PRECEDENCE_TYPECAST       = 190;

    /**
     * Precedence for base elements of expressions, see c_expr in original grammar
     */
    const PRECEDENCE_ATOM           = 666;

    /**
     * Precedence for UNION [ALL] and EXCEPT [ALL] set operations
     */
    const PRECEDENCE_SETOP_UNION     = 1;

    /**
     * Precedence for INTERSECT [ALL] set operation
     */
    const PRECEDENCE_SETOP_INTERSECT = 2;

    /**
     * Precedence for a base SELECT / VALUES statement in set operations
     */
    const PRECEDENCE_SETOP_SELECT    = 3;

    /**
     * Liberally add parentheses to expressions so that generated queries can run on any version of Postgres
     */
    const PARENTHESES_COMPAT = 'compat';

    /**
     * Add only parentheses that are needed for Postgres 9.5+, somewhat increasing readability of generated queries
     */
    const PARENTHESES_CURRENT = 'current';

    /**
     * Setting returned by getAssociativity() for right-associative operators
     */
    const ASSOCIATIVE_RIGHT = 'right';

    /**
     * Setting returned by getAssociativity() for left-associative operators
     */
    const ASSOCIATIVE_LEFT  = 'left';

    /**
     * Setting returned by getAssociativity() for non-associative operators
     */
    const ASSOCIATIVE_NONE  = 'nonassoc';


    protected $indentLevel = 0;

    protected $options = array(
        'indent'      => "    ",
        'linebreak'   => "\n",
        'wrap'        => 120,
        'parentheses' => self::PARENTHESES_CURRENT
    );

    /**
     * Dummy typecast expression used for checks with argumentNeedsParentheses()
     * @var nodes\expressions\TypecastExpression
     */
    private $_dummyTypecast;

    /**
     * Returns the precedence for operator represented by given Node in pre-9.5 Postgres
     *
     * @param nodes\ScalarExpression $expression a node that can appear in scalar expression
     * @param bool                   $right
     * @return integer
     */
    protected function getPrecedenceCompat(nodes\ScalarExpression $expression, $right = false)
    {
        if ($expression instanceof nodes\expressions\BetweenExpression) {
            return ($right && 'not' === substr($expression->operator, 0, 3))
                   ? self::PRECEDENCE_NOT : self::PRECEDENCE_BETWEEN;

        } elseif ($expression instanceof nodes\expressions\CollateExpression) {
            return self::PRECEDENCE_COLLATE;

        } elseif ($expression instanceof nodes\expressions\InExpression) {
            return self::PRECEDENCE_IN;

        } elseif ($expression instanceof nodes\expressions\IsOfExpression) {
            return self::PRECEDENCE_OLD_IS;

        } elseif ($expression instanceof nodes\expressions\LogicalExpression) {
            return 'or' === $expression->operator ? self::PRECEDENCE_OR : self::PRECEDENCE_AND;

        } elseif ($expression instanceof nodes\expressions\OperatorExpression) {
            switch ($expression->operator) {
            case 'not':
                return self::PRECEDENCE_NOT;

            case '=':
                return self::PRECEDENCE_OLD_EQUALITY;

            case '<':
            case '>':
                return self::PRECEDENCE_OLD_INEQUALITY;

            case 'overlaps':
                return self::PRECEDENCE_OVERLAPS;

            case 'is null':
            case 'is not null':
            case 'is true':
            case 'is not true':
            case 'is false':
            case 'is not false':
            case 'is unknown':
            case 'is not unknown':
            case 'is document':
            case 'is not document':
            case 'is distinct from':
            case 'is not distinct from':
                return self::PRECEDENCE_OLD_IS;

            case '+':
            case '-':
                // if no left operand is present, then this is an unary variant with higher precedence
                return $expression->left ? self::PRECEDENCE_ADDITION : self::PRECEDENCE_UNARY_MINUS;

            case '*':
            case '/':
            case '%':
                return self::PRECEDENCE_MULTIPLICATION;

            case '^':
                return self::PRECEDENCE_EXPONENTIATION;

            case 'at time zone':
                return self::PRECEDENCE_TIME_ZONE;

            default:
                // generic operator
                return $expression->right ? self::PRECEDENCE_GENERIC_OP : self::PRECEDENCE_POSTFIX_OP;
            }

        } elseif ($expression instanceof nodes\expressions\PatternMatchingExpression) {
            return ($right && 'not' === substr($expression->operator, 0, 3))
                   ? self::PRECEDENCE_NOT : self::PRECEDENCE_PATTERN;

        } elseif ($expression instanceof nodes\expressions\TypecastExpression) {
            return self::PRECEDENCE_TYPECAST;

        } else {
            return self::PRECEDENCE_ATOM;
        }
    }


    /**
     * Returns the precedence for operator represented by given Node in Postgres 9.5+
     *
     * @param nodes\ScalarExpression $expression a node that can appear in scalar expression
     * @return integer
     */
    protected function getPrecedence(nodes\ScalarExpression $expression)
    {
        if ($expression instanceof nodes\expressions\BetweenExpression) {
            return self::PRECEDENCE_BETWEEN;

        } elseif ($expression instanceof nodes\expressions\PatternMatchingExpression) {
            return self::PRECEDENCE_PATTERN;

        } elseif ($expression instanceof nodes\expressions\IsOfExpression) {
            return self::PRECEDENCE_IS;

        } elseif ($expression instanceof nodes\expressions\OperatorExpression) {
            switch ($expression->operator) {
            case 'not':
                return self::PRECEDENCE_NOT;

            case '=':
            case '<':
            case '>':
            case '<=':
            case '>=':
            case '!=':
            case '<>':
                return self::PRECEDENCE_COMPARISON;

            case 'overlaps':
                return self::PRECEDENCE_OVERLAPS;

            case 'is null':
            case 'is not null':
            case 'is true':
            case 'is not true':
            case 'is false':
            case 'is not false':
            case 'is unknown':
            case 'is not unknown':
            case 'is document':
            case 'is not document':
            case 'is distinct from':
            case 'is not distinct from':
                return self::PRECEDENCE_IS;

            case '+':
            case '-':
                // if no left operand is present, then this is an unary variant with higher precedence
                return $expression->left ? self::PRECEDENCE_ADDITION : self::PRECEDENCE_UNARY_MINUS;

            case '*':
            case '/':
            case '%':
                return self::PRECEDENCE_MULTIPLICATION;

            case '^':
                return self::PRECEDENCE_EXPONENTIATION;

            case 'at time zone':
                return self::PRECEDENCE_TIME_ZONE;

            default:
                // generic operator
                return $expression->right ? self::PRECEDENCE_GENERIC_OP : self::PRECEDENCE_POSTFIX_OP;
            }

        } else {
            return $this->getPrecedenceCompat($expression);
        }
    }

    /**
     * Returns the precedence for set operation represented by given Node
     *
     * @param SelectCommon $statement a node that can appear in set operation
     * @return integer
     */
    protected function getSetOpPrecedence(SelectCommon $statement)
    {
        if (!($statement instanceof SetOpSelect)) {
            return self::PRECEDENCE_SETOP_SELECT;

        } else {
            switch ($statement->operator) {
            case 'intersect':
            case 'intersect all':
                return self::PRECEDENCE_SETOP_INTERSECT;
            case 'union':
            case 'union all':
            case 'except':
            case 'except all':
            default:
                return self::PRECEDENCE_SETOP_UNION;
            }
        }
    }

    /**
     * Checks whether a given SELECT node contains any of ORDER BY / LIMIT / OFFSET / locking clauses
     *
     * Nodes with these clauses should be always wrapped in parentheses when used in
     * set operations, as per spec these clauses apply to a result of set operation rather
     * than to its operands
     *
     * @param SelectCommon $statement
     * @return bool
     */
    protected function containsCommonClauses(SelectCommon $statement)
    {
        return 0 < count($statement->order) || 0 < count($statement->locking)
               || $statement->limit || $statement->offset;
    }

    /**
     * Returns whether the given Node represents a right-associative operator in pre-9.5 Postgres
     *
     * @param nodes\ScalarExpression $expression a node that can appear in scalar expression
     * @return bool
     */
    protected function isRightAssociativeCompat(nodes\ScalarExpression $expression)
    {
        if (!($expression instanceof nodes\expressions\OperatorExpression)) {
            return false;
        } else {
            return in_array($expression->operator, array('=', 'not'))
                   || (!$expression->left && in_array($expression->operator, array('+', '-')));
        }
    }

    /**
     * Returns associativity of operator represented by a given Node in Postgres 9.5+
     *
     * @param nodes\ScalarExpression $expression
     * @return string
     */
    protected function getAssociativity(nodes\ScalarExpression $expression)
    {
        if ($expression instanceof nodes\expressions\TypecastExpression
            || $expression instanceof nodes\expressions\CollateExpression
            || $expression instanceof nodes\Indirection
        ) {
            return self::ASSOCIATIVE_LEFT;

        } elseif ($expression instanceof nodes\expressions\OperatorExpression) {
            if (!$expression->left && in_array($expression->operator, array('not', '+', '-'))) {
                return self::ASSOCIATIVE_RIGHT;

            } elseif (!in_array($expression->operator, array(
                          '=', '<', '>', '<=', '>=', '!=', '<>',
                          'overlaps', 'is null', 'is not null', 'is true', 'is not true',
                          'is false', 'is not false', 'is unknown', 'is not unknown',
                          'is document', 'is not document', 'is distinct from', 'is not distinct from'
                      ))
            ) {
                return self::ASSOCIATIVE_LEFT;
            }
        }

        return self::ASSOCIATIVE_NONE;
    }

    /**
     * Checks whether an argument of expression should be parenthesized when requiring compatibility
     *
     * @param nodes\ScalarExpression $argument
     * @param nodes\ScalarExpression $expression
     * @param bool                   $right
     * @return bool
     */
    protected function argumentNeedsParenthesesCompat(
        nodes\ScalarExpression $argument, nodes\ScalarExpression $expression, $right = false
    ) {
        $argumentPrecedence = $this->getPrecedenceCompat($argument, $right);

        if ($expression instanceof nodes\expressions\BetweenExpression) {
            // to be on a safe side, wrap just about everything in parentheses, it is quite
            // difficult to distinguish between a_expr and b_expr at this stage
            return $argumentPrecedence < ($right ? self::PRECEDENCE_TYPECAST : $this->getPrecedenceCompat($expression));

        } elseif ($expression instanceof nodes\expressions\OperatorExpression) {
            $rightAssociative = $this->isRightAssociativeCompat($expression);
            $exprPrecedence   = $this->getPrecedenceCompat($expression);
            if ($right) {
                return $argumentPrecedence < $exprPrecedence
                    || !$rightAssociative && $argumentPrecedence === $exprPrecedence;
            } else {
                return $argumentPrecedence < $exprPrecedence
                    || $rightAssociative && $argumentPrecedence === $exprPrecedence;
            }

        } elseif ($expression instanceof nodes\Indirection) {
            $isArray = $expression[0] instanceof nodes\ArrayIndexes;
            return ($isArray && $argumentPrecedence < self::PRECEDENCE_ATOM)
                    || (!$isArray && !($argument instanceof nodes\Parameter
                                       || $argument instanceof nodes\expressions\SubselectExpression
                                          && !$argument->operator));

        } elseif ($right) {
            return $argumentPrecedence <= $this->getPrecedenceCompat($expression);

        } else {
            return $argumentPrecedence < $this->getPrecedenceCompat($expression);
        }
    }

    /**
     * Checks whether an argument of expression should be parenthesized in Postgres 9.5+
     *
     * @param nodes\ScalarExpression $argument
     * @param nodes\ScalarExpression $expression
     * @param bool                   $right
     * @return bool
     */
    protected function argumentNeedsParentheses(
        nodes\ScalarExpression $argument, nodes\ScalarExpression $expression, $right = false
    ) {
        $argumentPrecedence = $this->getPrecedence($argument);

        if ($expression instanceof nodes\expressions\BetweenExpression) {
            // to be on a safe side, wrap just about everything in parentheses, it is quite
            // difficult to distinguish between a_expr and b_expr at this stage
            return $argumentPrecedence < ($right ? self::PRECEDENCE_TYPECAST : $this->getPrecedence($expression));

        } elseif ($expression instanceof nodes\Indirection) {
            $isArray = $expression[0] instanceof nodes\ArrayIndexes;
            return ($isArray && $argumentPrecedence < self::PRECEDENCE_ATOM)
                    || (!$isArray && !($argument instanceof nodes\Parameter
                                       || $argument instanceof nodes\expressions\SubselectExpression
                                          && !$argument->operator));
        }

        $expressionPrecedence = $this->getPrecedence($expression);
        switch ($this->getAssociativity($expression)) {
        case self::ASSOCIATIVE_NONE:
            return $argumentPrecedence <= $expressionPrecedence;
        case self::ASSOCIATIVE_RIGHT:
            return $argumentPrecedence < $expressionPrecedence
                   || !$right && $argumentPrecedence === $expressionPrecedence;
        case self::ASSOCIATIVE_LEFT:
            return $argumentPrecedence < $expressionPrecedence
                   || $right && $argumentPrecedence === $expressionPrecedence;
        }
    }

    /**
     * Adds parentheses around argument if its precedence is lower than that of parent expression
     *
     * @param nodes\ScalarExpression $argument
     * @param nodes\ScalarExpression $expression
     * @param bool                   $right
     * @return string
     */
    protected function optionalParentheses(
        nodes\ScalarExpression $argument, nodes\ScalarExpression $expression, $right = false
    ) {
        $needParens = $this->argumentNeedsParentheses($argument, $expression, $right)
                      || self::PARENTHESES_COMPAT === $this->options['parentheses']
                         && $this->argumentNeedsParenthesesCompat($argument, $expression, $right);

        return ($needParens ? '(' : '') . $argument->dispatch($this) . ($needParens ? ')' : '');
    }

    /**
     * Returns the string to indent the current expression
     *
     * @return string
     */
    protected function getIndent()
    {
        return str_repeat($this->options['indent'], $this->indentLevel);
    }

    /**
     * Joins the array elements into a string using given separator, adding line breaks and indents where needed
     *
     * If the builder was configured with 'wrap' and 'linebreak' options, the method will try to insert
     * line breaks between list items to keep the created lines' length below 'wrap' items. It will add a
     * proper indent after a line break.
     *
     * The parts are checked for existing linebreaks so that strings containing them (e.g. subselects)
     * will be added properly.
     *
     * @param string $lead      Leading keywords for expression list, e.g. 'select ' or 'order by '
     * @param array  $parts     Array of expressions
     * @param string $separator String to use for separating expressions
     * @return string
     */
    protected function implode($lead, array $parts, $separator = ',')
    {
        if (0 === count($parts)) {
            return $lead;

        } elseif (!$this->options['linebreak'] || !$this->options['wrap']) {
            return $lead . implode($separator . ' ', $parts);
        }

        $lineSep   = $separator . $this->options['linebreak'] . $this->getIndent();
        $indentLen = strlen($this->getIndent());
        $string    = $lead . array_shift($parts);
        $lineLen   = (false === $lastBreak = strrpos($string, $this->options['linebreak']))
                     ? strlen($string) : strlen($string) - $lastBreak;
        $sepLen    = strlen($separator) + 1;
        foreach ($parts as $part) {
            $partLen = strlen($part);
            if (false !== ($lastBreak = strrpos($part, $this->options['linebreak']))) {
                $firstBreak = strpos($part, $this->options['linebreak']);
                if ($lineLen + $firstBreak < $this->options['wrap']) {
                    $string .= $separator . ' ' . $part;
                } else {
                    $string .= $lineSep . $part;
                }
                $lineLen = $partLen - $lastBreak;

            } elseif ($lineLen + $partLen < $this->options['wrap']) {
                $string  .= $separator . ' ' . $part;
                $lineLen += $partLen + $sepLen;

            } else {
                $string  .= $lineSep . $part;
                $lineLen  = $indentLen + $partLen;
            }
        }
        return $string;
    }

    /**
     * Adds string representations of clauses defined in SelectCommon to an array
     *
     * @param array        $clauses
     * @param SelectCommon $statement
     * @return void
     */
    protected function addCommonSelectClauses(array &$clauses, SelectCommon $statement)
    {
        $indent = $this->getIndent();
        $this->indentLevel++;
        if (0 < count($statement->order)) {
            $clauses[] = $this->implode($indent . 'order by ', $statement->order->dispatch($this), ',');
        }
        if ($statement->limit) {
            $clauses[] = $indent . 'limit ' . $statement->limit->dispatch($this);
        }
        if ($statement->offset) {
            $clauses[] = $indent . 'offset ' . $statement->offset->dispatch($this);
        }
        if (0 < count($statement->locking)) {
            $clauses[] = $this->implode($indent, $statement->locking->dispatch($this), '');
        }
        $this->indentLevel--;
    }

    public function __construct(array $options = array())
    {
        $this->options = array_merge($this->options, $options);

        $this->_dummyTypecast = new nodes\expressions\TypecastExpression(
            new nodes\Constant('dummy'),
            new nodes\TypeName(new nodes\QualifiedName(array('dummy')))
        );
    }

    public function walkSelectStatement(Select $statement)
    {
        $clauses = array();
        if (0 < count($statement->with)) {
            $clauses[] = $statement->with->dispatch($this);
        }

        $indent  = $this->getIndent();
        $list    = $indent . 'select ';
        $this->indentLevel++;
        if (true === $statement->distinct) {
            $list .= 'distinct ';
        } elseif ($statement->distinct instanceof nodes\lists\ExpressionList) {
            $list .= $this->implode('distinct on (', $statement->distinct->dispatch($this), ',') . ') ';
        }
        $clauses[] = $this->implode($list, $statement->list->dispatch($this), ',');

        if (0 < count($statement->from)) {
            $clauses[] = $this->implode($indent . 'from ', $statement->from->dispatch($this), ',');
        }
        if ($statement->where->condition) {
            $clauses[] = $indent . 'where ' . $statement->where->dispatch($this);
        }
        if (0 < count($statement->group)) {
            $clauses[] = $this->implode($indent . 'group by ', $statement->group->dispatch($this), ',');
        }
        if ($statement->having->condition) {
            $clauses[] = $indent . 'having ' . $statement->having->dispatch($this);
        }
        if (0 < count($statement->window)) {
            $clauses[] = $this->implode($indent . 'window ', $statement->window->dispatch($this), ',');
        }
        $this->indentLevel--;

        $this->addCommonSelectClauses($clauses, $statement);

        return implode($this->options['linebreak'] ?: ' ', $clauses);
    }

    public function walkSetOpSelectStatement(SetOpSelect $statement)
    {
        $indent = $this->getIndent();
        $parts  = array();

        if (0 < count($statement->with)) {
            $parts[] = $statement->with->dispatch($this);
        }

        if ($this->containsCommonClauses($statement->left)
            || $this->getSetOpPrecedence($statement->left) < $this->getSetOpPrecedence($statement)
        ) {
            $this->indentLevel++;
            $part = $indent . '(' . $this->options['linebreak'] . $statement->left->dispatch($this);
            $this->indentLevel--;
            $parts[] = $part . $this->options['linebreak'] . $indent . ')';

        } else {
            $parts[] = $statement->left->dispatch($this);
        }

        $parts[] = $indent . $statement->operator;

        if ($this->containsCommonClauses($statement->right)
            || $this->getSetOpPrecedence($statement->right) <= $this->getSetOpPrecedence($statement)
        ) {
            $this->indentLevel++;
            $part = $indent . '(' . $this->options['linebreak'] . $statement->right->dispatch($this);
            $this->indentLevel--;
            $parts[] = $part . $this->options['linebreak'] . $indent . ')';

        } else {
            $parts[] = $statement->right->dispatch($this);
        }

        $this->addCommonSelectClauses($parts, $statement);

        return implode($this->options['linebreak'] ?: ' ', $parts);
    }

    public function walkValuesStatement(Values $statement)
    {
        $sql  = $this->getIndent() . 'values' . ($this->options['linebreak'] ?: ' ');
        $this->indentLevel++;
        $rows = $statement->rows->dispatch($this);
        $this->indentLevel--;

        $parts = array($sql . implode(',' . ($this->options['linebreak'] ?: ' '), $rows));

        $this->addCommonSelectClauses($parts, $statement);

        return implode($this->options['linebreak'] ?: ' ', $parts);
    }

    public function walkDeleteStatement(Delete $statement)
    {
        $clauses = array();
        if (0 < count($statement->with)) {
            $clauses[] = $statement->with->dispatch($this);
        }
        $indent = $this->getIndent();
        $this->indentLevel++;
        $clauses[] = $indent . 'delete from ' . $statement->relation->dispatch($this);

        if (0 < count($statement->using)) {
            $clauses[] = $this->implode($indent . 'using ', $statement->using->dispatch($this), ',');
        }
        if ($statement->where->condition) {
            $clauses[] = $indent . 'where ' . $statement->where->dispatch($this);
        }
        if (0 < count($statement->returning)) {
            $clauses[] = $this->implode($indent . 'returning ', $statement->returning->dispatch($this), ',');
        }
        $this->indentLevel--;

        return implode($this->options['linebreak'] ?: ' ', $clauses);
    }

    public function walkInsertStatement(Insert $statement)
    {
        $clauses = array();
        if (0 < count($statement->with)) {
            $clauses[] = $statement->with->dispatch($this);
        }

        $indent = $this->getIndent();
        $this->indentLevel++;

        $clauses[] = $indent . 'insert into ' . $statement->relation->dispatch($this);
        if (0 < count($statement->cols)) {
            $clauses[] = $this->implode($this->getIndent() . '(', $statement->cols->dispatch($this), ',') . ')';
        }
        if (!$statement->values) {
            $clauses[] = $indent . 'default values';
        } else {
            if ($statement->overriding) {
                $clauses[] = $indent . 'overriding ' . $statement->overriding . ' value';
            }
            $this->indentLevel--;
            $clauses[] = $statement->values->dispatch($this);
            $this->indentLevel++;
        }
        if ($statement->onConflict) {
            $clauses[] = $indent . 'on conflict ' . $statement->onConflict->dispatch($this);
        }
        if (0 < count($statement->returning)) {
            $clauses[] = $this->implode($indent . 'returning ', $statement->returning->dispatch($this), ',');
        }
        $this->indentLevel--;

        return implode($this->options['linebreak'] ?: ' ', $clauses);
    }

    public function walkUpdateStatement(Update $statement)
    {
        $clauses = array();
        if (0 < count($statement->with)) {
            $clauses[] = $statement->with->dispatch($this);
        }

        $indent = $this->getIndent();
        $this->indentLevel++;

        $clauses[] = $indent . 'update ' . $statement->relation->dispatch($this);
        $clauses[] = $this->implode($indent . 'set ', $statement->set->dispatch($this), ',');
        if (0 < count($statement->from)) {
            $clauses[] = $this->implode($indent . 'from ', $statement->from->dispatch($this), ',');
        }
        if ($statement->where->condition) {
            $clauses[] = $indent . 'where ' . $statement->where->dispatch($this);
        }
        if (0 < count($statement->returning)) {
            $clauses[] = $this->implode($indent . 'returning ', $statement->returning->dispatch($this), ',');
        }
        $this->indentLevel--;

        return implode($this->options['linebreak'] ?: ' ', $clauses);
    }

    public function walkArrayIndexes(nodes\ArrayIndexes $node)
    {
        return '['
               . ($node->lower ? $node->lower->dispatch($this) : '')
               . ($node->isSlice ? ' : ' : '')
               . ($node->upper ? $node->upper->dispatch($this) : '')
               . ']';
    }

    public function walkColumnReference(nodes\ColumnReference $node)
    {
        return ($node->catalog ? $node->catalog->dispatch($this) . '.' : '')
               . ($node->schema ? $node->schema->dispatch($this) . '.' : '')
               . ($node->relation ? $node->relation->dispatch($this) . '.' : '')
               . $node->column->dispatch($this);
    }

    public function walkCommonTableExpression(nodes\CommonTableExpression $node)
    {
        $this->indentLevel++;
        $sql = $node->alias->dispatch($this) . ' '
               . (0 < count($node->columnAliases) ? '(' . implode(', ', $node->columnAliases->dispatch($this)) . ') ' : '')
               . 'as (' . $this->options['linebreak'] . $node->statement->dispatch($this);
        $this->indentLevel--;

        return $sql . $this->options['linebreak'] . $this->getIndent() . ')';
    }

    /**
     *
     * @param nodes\Constant $node
     * @throws exceptions\InvalidArgumentException
     * @return string
     */
    public function walkConstant(nodes\Constant $node)
    {
        switch ($node->type) {
        case Token::TYPE_RESERVED_KEYWORD:
        case Token::TYPE_INTEGER:
        case Token::TYPE_FLOAT:
            return $node->value;

        case Token::TYPE_BINARY_STRING:
            return "b'" . $node->value ."'";

        case Token::TYPE_HEX_STRING:
            return "x'" . $node->value . "'";

        case Token::TYPE_NCHAR_STRING: // don't bother with generating N'...'
        case Token::TYPE_STRING:
            if (false === strpos($node->value, "'") && false === strpos($node->value, '\\')) {
                return "'" . $node->value . "'";

            } elseif (false === strpos($node->value . '$', '$$')) {
                return '$$' . $node->value . '$$';

            } else {
                $i = 1;
                while (false !== strpos($node->value . '$', '$_' . $i . '$')) {
                    $i++;
                }
                return '$_' . $i . '$' . $node->value . '$_' . $i . '$';
            }
            break;

        default:
            throw new exceptions\InvalidArgumentException(sprintf('Unexpected constant type %d', $node->type));
        }
    }

    public function walkFunctionCall(nodes\FunctionCall $node)
    {
        $arguments = (array)$node->arguments->dispatch($this);
        if ($node->variadic) {
            $arguments[] = 'variadic ' . array_pop($arguments);
        }
        $sql = ($node->name instanceof Node ? $node->name->dispatch($this) : (string)$node->name)  . '('
               . ($node->distinct ? 'distinct ' : '')
               . implode(', ', $arguments);
        if (0 < count($node->order)) {
            $sql .= ' order by ' . implode(',', $node->order->dispatch($this));
        }
        $sql .= ')';

        return $sql;
    }

    public function walkIdentifier(nodes\Identifier $node)
    {
        if (preg_match('/^[a-z_][a-z_0-9\$]*$/D', $node->value) && !Lexer::isKeyword($node->value)) {
            return $node->value;
        } else {
            return '"' . str_replace('"', '""', $node->value) . '"';
        }
    }

    public function walkIndirection(nodes\Indirection $node)
    {
        $sql = $this->optionalParentheses($node->expression, $node, false);
        /* @var Node $item */
        foreach ($node as $item) {
            if ($item instanceof nodes\ArrayIndexes) {
                $sql .= $item->dispatch($this);
            } else {
                $sql .= '.' . $item->dispatch($this);
            }
        }

        return $sql;
    }

    public function walkLockingElement(nodes\LockingElement $node)
    {
        $sql = 'for ' . $node->strength;
        if (0 < count($node)) {
            $sql .= ' of ' . implode(', ', $this->walkGenericNodeList($node));
        }
        if ($node->noWait) {
            $sql .= ' nowait';
        } elseif ($node->skipLocked) {
            $sql .= ' skip locked';
        }
        return $sql;
    }

    public function walkOrderByElement(nodes\OrderByElement $node)
    {
        $sql = $node->expression->dispatch($this);
        if ($node->direction) {
            $sql .= ' ' . $node->direction;
            if ('using' === $node->direction) {
                $sql .= ' ' . $node->operator;
            }
        }
        if ($node->nullsOrder) {
            $sql .= ' nulls ' . $node->nullsOrder;
        }
        return $sql;
    }

    public function walkParameter(nodes\Parameter $node)
    {
        switch ($node->type) {
        case Token::TYPE_POSITIONAL_PARAM:
            return '$' . $node->value;
            break;
        case Token::TYPE_NAMED_PARAM:
            return ':' . $node->value;
            break;
        default:
            throw new exceptions\InvalidArgumentException(sprintf('Unexpected parameter type %d', $node->type));
        }
    }

    public function walkQualifiedName(nodes\QualifiedName $node)
    {
        return ($node->catalog ? $node->catalog->dispatch($this) . '.' : '')
               . ($node->schema ? $node->schema->dispatch($this) . '.' : '')
               . $node->relation->dispatch($this);
    }

    public function walkSetTargetElement(nodes\SetTargetElement $node)
    {
        $sql = $node->name->dispatch($this);
        /* @var Node $item */
        foreach ($node as $item) {
            $sql .= ($item instanceof nodes\ArrayIndexes ? '' : '.') . $item->dispatch($this);
        }
        return $sql;
    }

    public function walkSingleSetClause(nodes\SingleSetClause $node)
    {
        return $node->column->dispatch($this) . ' = ' . $node->value->dispatch($this);
    }

    public function walkMultipleSetClause(nodes\MultipleSetClause $node)
    {
        return '(' . implode(', ', $node->columns->dispatch($this)) . ') = '
               . $node->value->dispatch($this);
    }

    public function walkSetToDefault(nodes\SetToDefault $node)
    {
        return 'default';
    }

    public function walkStar(nodes\Star $node)
    {
        return '*';
    }

    public function walkTargetElement(nodes\TargetElement $node)
    {
        return $node->expression->dispatch($this)
               . ($node->alias ? ' as ' . $node->alias->dispatch($this) : '');
    }

    public function walkTypeName(nodes\TypeName $node)
    {
        $sql = $node->setOf ? 'setof ' : '';
        if ($node instanceof nodes\IntervalTypeName) {
            $sql .= 'interval' . ($node->mask ? ' ' . $node->mask : '');
        } else {
            $sql .= $node->name->dispatch($this);
        }
        if (0 < count($node->modifiers)) {
            $sql .= '(' . implode(', ', $node->modifiers->dispatch($this)) . ')';
        }
        if ($node->bounds) {
            foreach ($node->bounds as $bound) {
                $sql .= '[' . (-1 === $bound ? '' : $bound) . ']';
            }
        }
        return $sql;
    }

    public function walkWhereOrHavingClause(nodes\WhereOrHavingClause $node)
    {
        return $node->condition ? $node->condition->dispatch($this) : '';
    }

    public function walkWindowDefinition(nodes\WindowDefinition $node)
    {
        // name should only be set for windows appearing in WINDOW clause
        if ($node->name) {
            $sql = $node->name->dispatch($this) . ' as (';
        } else {
            $sql = '(';
        }
        $parts = array();
        if ($node->refName) {
            $parts[] = $node->refName->dispatch($this);
        }
        if (0 < count($node->partition)) {
            $parts[] = 'partition by ' . implode(', ', $node->partition->dispatch($this));
        }
        if (0 < count($node->order)) {
            $parts[] = 'order by ' . implode(', ', $node->order->dispatch($this));
        }
        if ($node->frame) {
            $parts[] = $node->frame->dispatch($this);
        }
        return $sql . implode(' ', $parts) . ')';
    }

    public function walkWindowFrameClause(nodes\WindowFrameClause $node)
    {
        $sql = $node->type . ' ';
        if (!$node->end) {
            $sql .= $node->start->dispatch($this);
        } else {
            $sql .= 'between ' . $node->start->dispatch($this) . ' and ' . $node->end->dispatch($this);
        }
        if ($node->exclusion) {
            $sql .= ' exclude ' . $node->exclusion;
        }
        return $sql;
    }

    public function walkWindowFrameBound(nodes\WindowFrameBound $node)
    {
        if ($node->value) {
            return $node->value->dispatch($this) . ' ' . $node->direction;

        } elseif (in_array($node->direction, array('preceding', 'following'))) {
            return 'unbounded ' . $node->direction;

        } else {
            return $node->direction;
        }
    }

    public function walkWithClause(nodes\WithClause $node)
    {
        return $this->implode(
            $this->getIndent() . 'with ' . ($node->recursive ? 'recursive ' : ''),
            $this->walkGenericNodeList($node), ','
        );
    }

    protected function recursiveArrayExpression(nodes\expressions\ArrayExpression $expression, $keyword = true)
    {
        $items = array();
        foreach ($expression as $item) {
            if ($item instanceof nodes\expressions\ArrayExpression) {
                $items[] = $this->recursiveArrayExpression($item, false);
            } else {
                /* @var Node $item */
                $items[] = $item->dispatch($this);
            }
        }
        return ($keyword ? 'array' : '') . '[' . implode(', ', $items) . ']';
    }

    public function walkArrayExpression(nodes\expressions\ArrayExpression $expression)
    {
        return $this->recursiveArrayExpression($expression, true);
    }

    public function walkBetweenExpression(nodes\expressions\BetweenExpression $expression)
    {
        $sql = $this->optionalParentheses($expression->argument, $expression, false)
               . ' ' . $expression->operator . ' ';

        $sql .= $this->optionalParentheses($expression->left, $expression, true)
                . ' and '
                . $this->optionalParentheses($expression->right, $expression, true);

        return $sql;
    }

    public function walkCaseExpression(nodes\expressions\CaseExpression $expression)
    {
        $clauses = array();
        if ($expression->argument) {
            $clauses[] = $expression->argument->dispatch($this);
        }
        /* @var nodes\expressions\WhenExpression $whenClause */
        foreach ($expression as $whenClause) {
            $clauses[] = 'when ' . $whenClause->when->dispatch($this)
                         . ' then ' . $whenClause->then->dispatch($this);
        }

        if ($expression->else) {
            $clauses[] = 'else ' . $expression->else->dispatch($this);
        }

        return 'case ' . implode(' ', $clauses) . ' end';
    }

    public function walkCollateExpression(nodes\expressions\CollateExpression $expression)
    {
        return $this->optionalParentheses($expression->argument, $expression, false)
               . ' collate ' . $expression->collation->dispatch($this);
    }

    public function walkFunctionExpression(nodes\expressions\FunctionExpression $expression)
    {
        if (!$expression->withinGroup) {
            $sql = $this->walkFunctionCall($expression);

        } else {
            $arguments = (array)$expression->arguments->dispatch($this);
            if ($expression->variadic) {
                $arguments[] = 'variadic ' . array_pop($arguments);
            }
            $sql = ($expression->name instanceof Node ? $expression->name->dispatch($this) : (string)$expression->name)  . '('
                   . ($expression->distinct ? 'distinct ' : '')
                   . implode(', ', $arguments) . ')'
                   . ' within group (order by '
                   . implode(', ', $expression->order->dispatch($this)) . ')';
        }

        if ($expression->filter) {
            $sql .= ' filter (where ' . $expression->filter->dispatch($this) . ')';
        }
        if ($expression->over) {
            $sql .= ' over ' . $expression->over->dispatch($this);
        }
        return $sql;
    }

    public function walkInExpression(nodes\expressions\InExpression $expression)
    {
        if ($expression->right instanceof SelectCommon) {
            $this->indentLevel++;
            $right  = '(' . $this->options['linebreak'] . $expression->right->dispatch($this);
            $this->indentLevel--;
            $right .= $this->options['linebreak'] . $this->getIndent() . ')';

        } else {
            $right = '(' . implode(', ', $expression->right->dispatch($this)) . ')';
        }

        return $this->optionalParentheses($expression->left, $expression, false)
               . ' ' . $expression->operator . ' ' . $right;
    }

    public function walkIsOfExpression(nodes\expressions\IsOfExpression $expression)
    {
        return $this->optionalParentheses($expression->left, $expression, false)
               . ' ' . $expression->operator . ' ('
               . implode(', ', $expression->right->dispatch($this)) . ')';
    }

    public function walkLogicalExpression(nodes\expressions\LogicalExpression $expression)
    {
        $parent = $expression;
        do {
            $parent = $parent->getParentNode();
        } while ($parent instanceof nodes\expressions\LogicalExpression);

        if (!($verbose = $parent instanceof nodes\WhereOrHavingClause)) {
            $delimiter = ' ' . $expression->operator . ' ';
        } else {
            $delimiter = ($this->options['linebreak'] ?: ' ') . $this->getIndent() . $expression->operator . ' ';
        }

        $items     = array();

        /* @var nodes\ScalarExpression $item */
        foreach ($expression as $item) {
            if ($this->getPrecedence($item) >= $this->getPrecedence($expression)) {
                $items[] = $item->dispatch($this);
            } elseif (!$verbose) {
                $items[] = '(' . $item->dispatch($this) . ')';
            } else {
                $this->indentLevel++;
                $nested = '(' . $this->options['linebreak'] . $this->getIndent() . $item->dispatch($this);
                $this->indentLevel--;
                $items[] = $nested . $this->options['linebreak'] . $this->getIndent() . ')';
            }
        }

        return implode($delimiter, $items);
    }

    public function walkOperatorExpression(nodes\expressions\OperatorExpression $expression)
    {
        if ($expression->left) {
            $sql = $this->optionalParentheses($expression->left, $expression, false) . ' ';
        } else {
            $sql = '';
        }

        $sql .= $expression->operator;

        if ($expression->right) {
            $sql .= ' ' . $this->optionalParentheses($expression->right, $expression, true);
        }

        return $sql;
    }

    public function walkPatternMatchingExpression(nodes\expressions\PatternMatchingExpression $expression)
    {
        $sql = $this->optionalParentheses($expression->argument, $expression, false)
               . ' ' . $expression->operator . ' '
               . $this->optionalParentheses($expression->pattern, $expression, true);
        if ($expression->escape) {
            $sql .= ' escape ' . $this->optionalParentheses($expression->escape, $expression, true);
        }
        return $sql;
    }

    public function walkRowExpression(nodes\expressions\RowExpression $expression)
    {
        if ($expression->getParentNode() instanceof nodes\lists\RowList) {
            return $this->implode($this->getIndent() . '(', $this->walkGenericNodeList($expression), ',') . ')';
        } elseif (count($expression) < 2) {
            return 'row(' . implode(', ', $this->walkGenericNodeList($expression)) . ')';
        } else {
            return '(' . implode(', ', $this->walkGenericNodeList($expression)) . ')';
        }
    }

    public function walkValueList(nodes\lists\ValueList $expression)
    {
        return 'VALUES (' . implode(', ', $this->walkGenericNodeList($expression)) . ')';
    }


    public function walkSubselectExpression(nodes\expressions\SubselectExpression $expression)
    {
        $this->indentLevel++;
        $sql = $expression->operator . '(' . $this->options['linebreak']
               . $expression->query->dispatch($this);
        $this->indentLevel--;

        return $sql . $this->options['linebreak'] . $this->getIndent() . ')';
    }

    public function walkTypecastExpression(nodes\expressions\TypecastExpression $expression)
    {
        return $this->optionalParentheses($expression->argument, $expression, false)
               . '::' . $expression->type->dispatch($this);
    }

    public function walkGroupingExpression(nodes\expressions\GroupingExpression $expression)
    {
        return 'grouping(' . implode(', ', $this->walkGenericNodeList($expression)) . ')';
    }


    /**
     * Most of the lists do not have any additional features and may be handled by a generic method
     *
     * @param NodeList $list
     * @return array
     */
    public function walkGenericNodeList(NodeList $list)
    {
        $items = array();
        /* @var Node $item */
        foreach ($list as $item) {
            $items[] = $item->dispatch($this);
        }
        return $items;
    }

    public function walkFunctionArgumentList(nodes\lists\FunctionArgumentList $list)
    {
        $items = array();
        /* @var nodes\ScalarExpression $argument */
        foreach ($list as $key => $argument) {
            if (is_int($key)) {
                $items[] = $argument->dispatch($this);
            } else {
                $items[] = $key . ' := ' . $argument->dispatch($this);
            }
        }
        return $items;
    }

    public function walkColumnDefinition(nodes\range\ColumnDefinition $node)
    {
        return $node->name->dispatch($this)
               . ' ' . $node->type->dispatch($this)
               . ($node->collation ? ' collate ' . $node->collation->dispatch($this) : '');
    }

    protected function getFromItemAliases(nodes\range\FromElement $rangeItem)
    {
        $sql = ' as';
        if ($rangeItem->tableAlias) {
            $sql .= ' ' . $rangeItem->tableAlias->dispatch($this);
        }
        if ($rangeItem->columnAliases) {
            $sql .= ' (' . implode(', ', $rangeItem->columnAliases->dispatch($this)) . ')';
        }
        return $sql;
    }

    public function walkRangeFunctionCall(nodes\range\FunctionCall $rangeItem)
    {
        $sql = ($rangeItem->lateral ? 'lateral ' : '') . $rangeItem->function->dispatch($this);

        if ($rangeItem->withOrdinality) {
            $sql .= ' with ordinality';
        }
        if ($rangeItem->tableAlias || $rangeItem->columnAliases) {
            $sql .= $this->getFromItemAliases($rangeItem);
        }

        return $sql;
    }

    public function walkRowsFrom(nodes\range\RowsFrom $rangeItem)
    {
        $sql = ($rangeItem->lateral ? 'lateral ' : '') . 'rows from('
               . implode(', ', $rangeItem->function->dispatch($this)) . ')';

        if ($rangeItem->withOrdinality) {
            $sql .= ' with ordinality';
        }
        if ($rangeItem->tableAlias || $rangeItem->columnAliases) {
            $sql .= $this->getFromItemAliases($rangeItem);
        }

        return $sql;
    }

    public function walkRowsFromElement(nodes\range\RowsFromElement $node)
    {
        $sql = $node->function->dispatch($this);
        if (count($node->columnAliases) > 0) {
            $sql .= ' as (' . implode(', ', $node->columnAliases->dispatch($this)) . ')';
        }
        return $sql;
    }

    public function walkJoinExpression(nodes\range\JoinExpression $rangeItem)
    {
        $sql  = ($rangeItem->tableAlias || $rangeItem->columnAliases) ? '(' : '';
        $sql .= $rangeItem->left->dispatch($this);

        if ($rangeItem->natural) {
            $sql .= ' natural';
        }
        $sql .= ' ' . $rangeItem->type . ' join ';

        if ($rangeItem->right instanceof nodes\range\JoinExpression) {
            $sql .= '(' . $rangeItem->right->dispatch($this) . ')';
        } else {
            $sql .= $rangeItem->right->dispatch($this);
        }

        if ($rangeItem->on) {
            $sql .= ' on ' . $rangeItem->on->dispatch($this);

        } elseif ($rangeItem->using) {
            $sql .= ' using (' . implode(', ', $rangeItem->using->dispatch($this)) . ')';
        }

        if ($rangeItem->tableAlias || $rangeItem->columnAliases) {
            $sql .= ')' . $this->getFromItemAliases($rangeItem);
        }

        return $sql;
    }

    public function walkRelationReference(nodes\range\RelationReference $rangeItem)
    {
        $sql = (false === $rangeItem->inherit ? 'only ' : '')
               . $rangeItem->name->dispatch($this)
               . (true === $rangeItem->inherit ? ' *' : '');

        if ($rangeItem->tableAlias || $rangeItem->columnAliases) {
            $sql .= $this->getFromItemAliases($rangeItem);
        }

        return $sql;
    }

    public function walkRangeSubselect(nodes\range\Subselect $rangeItem)
    {
        $this->indentLevel++;
        $sql = ($rangeItem->lateral ? 'lateral (': '(') . $this->options['linebreak']
               . $rangeItem->query->dispatch($this);
        $this->indentLevel--;

        return $sql . $this->options['linebreak'] . $this->getIndent() . ')'
               . $this->getFromItemAliases($rangeItem);
    }

    public function walkInsertTarget(nodes\range\InsertTarget $target)
    {
        $sql = $target->relation->dispatch($this);

        if ($target->alias) {
            $sql .= ' as ' . $target->alias->dispatch($this);
        }

        return $sql;
    }

    public function walkUpdateOrDeleteTarget(nodes\range\UpdateOrDeleteTarget $target)
    {
        $sql = (false === $target->inherit ? 'only ' : '')
               . $target->relation->dispatch($this)
               . (true === $target->inherit ? ' *' : '');

        if ($target->alias) {
            $sql .= ' as ' . $target->alias->dispatch($this);
        }

        return $sql;
    }

    public function walkTableSample(nodes\range\TableSample $rangeItem)
    {
        $sql = $rangeItem->relation->dispatch($this)
               . ' tablesample ' . $rangeItem->method->dispatch($this)
               . ' (' . implode(', ', $rangeItem->arguments->dispatch($this)) . ')';

        if ($rangeItem->repeatable) {
            $sql .= ' repeatable(' . $rangeItem->repeatable->dispatch($this) . ')';
        }

        return $sql;
    }


    public function walkXmlElement(nodes\xml\XmlElement $xml)
    {
        $sql = 'xmlelement(name ' . $xml->name->dispatch($this);
        if (0 < count($xml->attributes)) {
            $sql .= ', xmlattributes(' . implode(', ', $xml->attributes->dispatch($this)) . ')';
        }
        if (0 < count($xml->content)) {
            $sql .= ', ' . implode(', ', $xml->content->dispatch($this));
        }
        return $sql . ')';
    }

    public function walkXmlForest(nodes\xml\XmlForest $xml)
    {
        return 'xmlforest(' . implode(', ', $this->walkGenericNodeList($xml)) . ')';
    }

    public function walkXmlParse(nodes\xml\XmlParse $xml)
    {
        return 'xmlparse(' . $xml->documentOrContent . ' ' . $xml->argument->dispatch($this)
               . ($xml->preserveWhitespace ? ' preserve whitespace' : '') . ')';
    }

    public function walkXmlPi(nodes\xml\XmlPi $xml)
    {
        return 'xmlpi(name ' . $xml->name->dispatch($this)
               . ($xml->content ? ', ' . $xml->content->dispatch($this) : '') . ')';
    }

    public function walkXmlRoot(nodes\xml\XmlRoot $xml)
    {
        return 'xmlroot(' . $xml->xml->dispatch($this)
               . ', version ' . ($xml->version ? $xml->version->dispatch($this) : 'no value')
               . ($xml->standalone ? ', standalone ' . $xml->standalone : '') . ')';
    }

    public function walkXmlSerialize(nodes\xml\XmlSerialize $xml)
    {
        return 'xmlserialize(' . $xml->documentOrContent . ' ' . $xml->argument->dispatch($this)
               . ' as ' . $xml->type->dispatch($this) . ')';
    }

    public function walkXmlTable(nodes\range\XmlTable $table)
    {
        $this->indentLevel++;
        $lines = array(($table->lateral ? 'lateral ' : '') . 'xmltable(');
        if (0 < count($table->namespaces)) {
            $lines[] = $this->getIndent() . 'xmlnamespaces(';

            $this->indentLevel++;
            $glue = $this->options['linebreak'] ? ',' . $this->options['linebreak'] . $this->getIndent() : ', ';
            $lines[] = $this->getIndent() . implode($glue, $this->walkGenericNodeList($table->namespaces));
            $this->indentLevel--;

            $lines[] = $this->getIndent() . '),';
        }

        $lines[] = $this->getIndent() . $this->optionalParentheses($table->rowExpression, $this->_dummyTypecast, true)
                   . ' passing by ref ' . $this->optionalParentheses($table->documentExpression, $this->_dummyTypecast, true)
                   . ' by ref';
        $glue    = $this->options['linebreak']
                   ? ',' . $this->options['linebreak'] . $this->getIndent() . '        ' // let's align columns
                   : ', ';
        $lines[] = $this->getIndent() . 'columns ' . implode($glue, $this->walkGenericNodeList($table->columns));

        $this->indentLevel--;
        $sql = implode($this->options['linebreak'] ?: ' ', $lines) . $this->options['linebreak'] . $this->getIndent() . ')';
        if ($table->tableAlias || $table->columnAliases) {
            $sql .= $this->getFromItemAliases($table);
        }

        return $sql;
    }

    public function walkXmlColumnDefinition(nodes\xml\XmlColumnDefinition $column)
    {
        $sql = $column->name->dispatch($this);

        if ($column->forOrdinality) {
            return $sql . ' for ordinality';
        }
        $sql .= ' ' . $column->type->dispatch($this);
        if ($column->path) {
            $sql .= ' path ' . $this->optionalParentheses($column->path, $this->_dummyTypecast, true);
        }
        if ($column->default) {
            $sql .= ' default ' . $this->optionalParentheses($column->default, $this->_dummyTypecast, true);
        }
        if (null !== $column->nullable) {
            $sql .= $column->nullable ? ' null' : ' not null';
        }
        return $sql;
    }

    public function walkXmlNamespace(nodes\xml\XmlNamespace $ns)
    {
        $sql = $this->optionalParentheses($ns->value, $this->_dummyTypecast, true);

        if (!$ns->alias) {
            return 'default ' . $sql;
        } else {
            return $sql . ' as ' . $ns->alias->dispatch($this);
        }
    }

    public function walkOnConflictClause(nodes\OnConflictClause $onConflict)
    {
        $sql = '';
        if ($onConflict->target) {
            if ($onConflict->target instanceof nodes\Identifier) {
                $sql .= 'on constraint ';
            }
            $sql .= $onConflict->target->dispatch($this);
        }
        $sql .= ' do ' . $onConflict->action;
        if ('update' === $onConflict->action) {
            $indent = $this->getIndent();
            $this->indentLevel++;

            $clauses = array('');
            $clauses[] = $this->implode($indent . 'set ', $onConflict->set->dispatch($this), ',');
            if ($onConflict->where->condition) {
                $clauses[] = $indent . 'where ' . $onConflict->where->dispatch($this);
            }

            $this->indentLevel--;

            $sql .= implode($this->options['linebreak'] ?: ' ', $clauses);
        }
        return $sql;
    }

    public function walkIndexParameters(nodes\IndexParameters $parameters)
    {
        $sql = '(' . implode(', ', $this->walkGenericNodeList($parameters)) . ')';
        if ($parameters->where->condition) {
            $sql .= ' where ' . $parameters->where->dispatch($this);
        }
        return $sql;
    }

    public function walkIndexElement(nodes\IndexElement $element)
    {
        if ($element->expression instanceof nodes\Identifier) {
            $sql = $element->expression->dispatch($this);
        } else {
            $sql = '(' . $element->expression->dispatch($this) . ')';
        }

        if ($element->collation) {
            $sql .= ' collate ' . $element->collation->dispatch($this);
        }
        if ($element->opClass) {
            $sql .= ' ' . $element->opClass->dispatch($this);
        }
        if ($element->direction) {
            $sql .= ' ' . $element->direction;
        }
        if ($element->nullsOrder) {
            $sql .= ' nulls ' . $element->nullsOrder;
        }

        return $sql;
    }


    public function walkEmptyGroupingSet(nodes\group\EmptyGroupingSet $empty)
    {
        return '()';
    }

    public function walkCubeOrRollupClause(nodes\group\CubeOrRollupClause $clause)
    {
        return $clause->type . '(' . implode(', ', $this->walkGenericNodeList($clause)) . ')';
    }

    public function walkGroupingSetsClause(nodes\group\GroupingSetsClause $clause)
    {
        return 'grouping sets(' . implode(', ', $this->walkGenericNodeList($clause)) . ')';
    }
}
