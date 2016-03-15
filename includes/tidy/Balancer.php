<?php
/**
 * An implementation of the tree building portion of the HTML5 parsing
 * spec.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Parser
 * @since 1.27
 * @author C. Scott Ananian, 2016
 */
namespace MediaWiki\Tidy;

use Wikimedia\Assert\Assert;
use Wikimedia\Assert\ParameterAssertionException;
use \ExplodeIterator;
use \IteratorAggregate;
use \ReverseArrayIterator;
use \Sanitizer;

# A note for future librarization[1] -- this file is a good candidate
# for splitting into an independent library, except that it is currently
# highly optimized for MediaWiki use.  It only implements the portions
# of the HTML5 tree builder used by tags supported by MediaWiki, and
# does not contain a true tokenizer pass, instead relying on
# comment stripping, attribute normalization, and escaping done by
# the MediaWiki Sanitizer.  It also deliberately avoids building
# a true DOM in memory, instead serializing elements to an output string
# as soon as possible (usually as soon as the tag is closed) to reduce
# its memory footprint.

# On the other hand, I've been pretty careful to note with comments in the
# code the places where this implementation omits features of the spec or
# depends on the MediaWiki Sanitizer.  Perhaps in the future we'll want to
# implement the missing pieces and make this a standalone PHP HTML5 parser.
# In order to do so, some sort of MediaWiki-specific API will need
# to be added to (a) allow the Balancer to bypass the tokenizer,
# and (b) support on-the-fly flattening instead of DOM node creation.

# [1]: https://www.mediawiki.org/wiki/Library_infrastructure_for_MediaWiki

/**
 * Utility constants and sets for the HTML5 tree building algorithm.
 * Sets are associative arrays indexed first by namespace and then by
 * lower-cased tag name.
 *
 * @ingroup Parser
 * @since 1.27
 */
class BalanceSets {
	const HTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';
	const MATHML_NAMESPACE = 'http://www.w3.org/1998/Math/MathML';
	const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

	public static $unsupportedSet = [
		self::HTML_NAMESPACE => [
			'html' => true, 'head' => true, 'body' => true, 'frameset' => true,
			'form' => true, 'frame' => true,
			'plaintext' => true, 'isindex' => true, 'textarea' => true,
			'xmp' => true, 'iframe' => true, 'noembed' => true,
			'noscript' => true, 'select' => true, 'script' => true,
			'title' => true
		]
	];

	public static $emptyElementSet = [
		self::HTML_NAMESPACE => [
			'area' => true, 'base' => true, 'basefont' => true,
			'bgsound' => true, 'br' => true, 'col' => true, 'command' => true,
			'embed' => true, 'frame' => true, 'hr' => true, 'img' => true,
			'input' => true, 'keygen' => true, 'link' => true, 'meta' => true,
			'param' => true, 'source' => true, 'track' => true, 'wbr' => true
		]
	];

	public static $headingSet = [
		self::HTML_NAMESPACE => [
			'h1' => true, 'h2' => true, 'h3' => true,
			'h4' => true, 'h5' => true, 'h6' => true
		]
	];

	public static $specialSet = [
		self::HTML_NAMESPACE => [
			'address' => true, 'applet' => true, 'area' => true,
			'article' => true, 'aside' => true, 'base' => true,
			'basefont' => true, 'bgsound' => true, 'blockquote' => true,
			'body' => true, 'br' => true, 'button' => true, 'caption' => true,
			'center' => true, 'col' => true, 'colgroup' => true, 'dd' => true,
			'details' => true, 'dir' => true, 'div' => true, 'dl' => true,
			'dt' => true, 'embed' => true, 'fieldset' => true,
			'figcaption' => true, 'figure' => true, 'footer' => true,
			'form' => true, 'frame' => true, 'frameset' => true, 'h1' => true,
			'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true,
			'h6' => true, 'head' => true, 'header' => true, 'hgroup' => true,
			'hr' => true, 'html' => true, 'iframe' => true, 'img' => true,
			'input' => true, 'isindex' => true, 'li' => true, 'link' => true,
			'listing' => true, 'main' => true, 'marquee' => true,
			'menu' => true, 'menuitem' => true, 'meta' => true, 'nav' => true,
			'noembed' => true, 'noframes' => true, 'noscript' => true,
			'object' => true, 'ol' => true, 'p' => true, 'param' => true,
			'plaintext' => true, 'pre' => true, 'script' => true,
			'section' => true, 'select' => true, 'source' => true,
			'style' => true, 'summary' => true, 'table' => true,
			'tbody' => true, 'td' => true, 'template' => true,
			'textarea' => true, 'tfoot' => true, 'th' => true, 'thead' => true,
			'title' => true, 'tr' => true, 'track' => true, 'ul' => true,
			'wbr' => true, 'xmp' => true
		],
		self::SVG_NAMESPACE => [
			'foreignobject' => true, 'desc' => true, 'title' => true
		],
		self::MATHML_NAMESPACE => [
			'mi' => true, 'mo' => true, 'mn' => true, 'ms' => true,
			'mtext' => true, 'annotation-xml' => true
		]
	];

	public static $addressDivPSet = [
		self::HTML_NAMESPACE => [
			'address' => true, 'div' => true, 'p' => true
		]
	];

	public static $tableSectionRowSet = [
		self::HTML_NAMESPACE => [
			'table' => true, 'thead' => true, 'tbody' => true,
			'tfoot' => true, 'tr' => true
		]
	];

	public static $impliedEndTagsSet = [
		self::HTML_NAMESPACE => [
			'dd' => true, 'dt' => true, 'li' => true, 'optgroup' => true,
			'option' => true, 'p' => true, 'rb' => true, 'rp' => true,
			'rt' => true, 'rtc' => true
		]
	];

	public static $thoroughImpliedEndTagsSet = [
		self::HTML_NAMESPACE => [
			'caption' => true, 'colgroup' => true, 'dd' => true, 'dt' => true,
			'li' => true, 'optgroup' => true, 'option' => true, 'p' => true,
			'rb' => true, 'rp' => true, 'rt' => true, 'rtc' => true,
			'tbody' => true, 'td' => true, 'tfoot' => true, 'th' => true,
			'thead' => true, 'tr' => true
		]
	];

	public static $tableCellSet = [
		self::HTML_NAMESPACE => [
			'td' => true, 'th' => true
		]
	];
	public static $tableContextSet = [
		self::HTML_NAMESPACE => [
			'table' => true, 'template' => true, 'html' => true
		]
	];

	public static $tableBodyContextSet = [
		self::HTML_NAMESPACE => [
			'tbody' => true, 'tfoot' => true, 'thead' => true,
			'template' => true, 'html' => true
		]
	];

	public static $tableRowContextSet = [
		self::HTML_NAMESPACE => [
			'tr' => true, 'template' => true, 'html' => true
		]
	];

	# OMITTED: formAssociatedSet, since we don't allow <form>

	public static $inScopeSet = [
		self::HTML_NAMESPACE => [
			'applet' => true, 'caption' => true, 'html' => true,
			'marquee' => true, 'object' => true,
			'table' => true, 'td' => true, 'template' => true,
			'th' => true
		],
		self::SVG_NAMESPACE => [
			'foreignobject' => true, 'desc' => true, 'title' => true
		],
		self::MATHML_NAMESPACE => [
			'mi' => true, 'mo' => true, 'mn' => true, 'ms' => true,
			'mtext' => true, 'annotation-xml' => true
		]
	];

	private static $inListItemScopeSet = null;
	public static function inListItemScopeSet() {
		if ( self::$inListItemScopeSet === null ) {
			self::$inListItemScopeSet = self::$inScopeSet;
			self::$inListItemScopeSet[self::HTML_NAMESPACE]['ol'] = true;
			self::$inListItemScopeSet[self::HTML_NAMESPACE]['ul'] = true;
		}
		return self::$inListItemScopeSet;
	}

	private static $inButtonScopeSet = null;
	public static function inButtonScopeSet() {
		if ( self::$inButtonScopeSet === null ) {
			self::$inButtonScopeSet = self::$inScopeSet;
			self::$inButtonScopeSet[self::HTML_NAMESPACE]['button'] = true;
		}
		return self::$inButtonScopeSet;
	}

	public static $inTableScopeSet = [
		self::HTML_NAMESPACE => [
			'html' => true, 'table' => true, 'template' => true
		]
	];

	public static $mathmlTextIntegrationPointSet = [
		self::MATHML_NAMESPACE => [
			'mi' => true, 'mo' => true, 'mn' => true, 'ms' => true,
			'mtext' => true
		]
	];

	public static $htmlIntegrationPointSet = [
		self::SVG_NAMESPACE => [
			'foreignobject' => true,
			'desc' => true,
			'title' => true
		]
	];
}

/**
 * A BalanceElement is a simplified version of a DOM Node.  The main
 * difference is that we only keep BalanceElements around for nodes
 * currently on the BalanceStack of open elements.  As soon as an
 * element is closed, with some minor exceptions relating to the
 * tree builder "adoption agency algorithm", the element and all its
 * children are serialized to a string using the flatten() method.
 * This keeps our memory usage low.
 *
 * @ingroup Parser
 * @since 1.27
 */
class BalanceElement {
	/**
	 * The namespace of the element.
	 * @var string $namespaceURI
	 */
	public $namespaceURI;
	/**
	 * The lower-cased name of the element.
	 * @var string $localName
	 */
	public $localName;
	/**
	 * Attributes for the element, as normalized by
	 * Sanitizer::safeEncodeTagAttributes. Attributes are space-separated
	 * and attribute values are double-quoted.  Elements with no
	 * attributes should have a zero-length string here.
	 * @var string $attribs
	 */
	public $attribs;

	/**
	 * Parent of this element, or the string "flat" if this element has
	 * already been flattened into its parent.
	 * @var string|null $parent
	 */
	public $parent;

	/**
	 * An array of children of this element.  Typically only the last
	 * child will be an actual BalanceElement object; the rest will
	 * be strings, representing either text nodes or flattened
	 * BalanceElement objects.
	 * @var array $children
	 */
	public $children;

	/**
	 * Make a new BalanceElement corresponding to the HTML DOM Element
	 * with the given localname, namespace, and attributes.
	 *
	 * @param string $namespaceURI The namespace of the element.
	 * @param string $localName The lowercased name of the tag.
	 * @param string $attribs Attributes of the element, as normalized
	 *   by Sanitizer:safeEncodeTagAttributes.
	 */
	public function __construct( $namespaceURI, $localName, $attribs ) {
		Assert::parameterType( 'string', $namespaceURI, '$namespaceURI' );
		Assert::parameterType( 'string', $localName, '$localName' );
		Assert::parameterType( 'string', $attribs, '$attribs' );

		$this->localName = $localName;
		$this->namespaceURI = $namespaceURI;
		$this->attribs = $attribs;
		$this->contents = '';
		$this->parent = null;
		$this->children = [];
	}

	/**
	 * Remove the given child from this element.
	 * @param BalanceElement $elt
	 */
	private function removeChild( $elt ) {
		Assert::precondition(
			$this->parent !== 'flat', "Can't removeChild after flattening $this"
		);
		Assert::parameterType( 'MediaWiki\Tidy\BalanceElement', $elt, '$elt' );
		Assert::parameter(
			$elt->parent === $this, 'elt', 'must have $this as a parent'
		);
		$idx = array_search( $elt, $this->children, true );
		Assert::parameter( $idx !== false, '$elt', 'must be a child of $this' );
		$elt->parent = null;
		array_splice( $this->children, $idx, 1 );
	}

	/**
	 * Find $a in the list of children and insert $b before it.
	 * @param BalanceElement $a
	 * @param BalanceElement|string $b
	 */
	public function insertBefore( $a, $b ) {
		Assert::precondition(
			$this->parent !== 'flat', "Can't insertBefore after flattening."
		);
		Assert::parameterType( 'MediaWiki\Tidy\BalanceElement', $a, '$a' );
		Assert::parameterType( 'MediaWiki\Tidy\BalanceElement|string', $b, '$b' );
		$idx = array_search( $a, $this->children, true );
		Assert::parameter( $idx !== false, '$a', 'must be a child of $this' );
		if ( is_string( $b ) ) {
			array_splice( $this->children, $idx, 0, [ $b ] );
		} else {
			Assert::parameter( $b->parent !== 'flat', '$b', "Can't be flat" );
			if ( $b->parent !== null ) {
				$b->parent->removeChild( $b );
			}
			array_splice( $this->children, $idx, 0, [ $b ] );
			$b->parent = $this;
		}
	}

	/**
	 * Append $elt to the end of the list of children.
	 * @param BalanceElement|string $elt
	 */
	public function appendChild( $elt ) {
		Assert::precondition(
			$this->parent !== 'flat', "Can't appendChild after flattening."
		);
		Assert::parameterType( 'MediaWiki\Tidy\BalanceElement|string', $elt, '$elt' );
		if ( is_string( $elt ) ) {
			array_push( $this->children, $elt );
			return;
		}
		// Remove $elt from parent, if it had one.
		if ( $elt->parent !== null ) {
			$elt->parent->removeChild( $elt );
		}
		array_push( $this->children, $elt );
		$elt->parent = $this;
	}

	/**
	 * Transfer all of the children of $elt to $this.
	 * @param BalanceElement $elt
	 */
	public function adoptChildren( $elt ) {
		Assert::parameterType( 'MediaWiki\Tidy\BalanceElement', $elt, '$elt' );
		Assert::precondition(
			$elt->parent !== 'flat', "Can't adoptChildren after flattening."
		);
		foreach ( $elt->children as $child ) {
			if ( !is_string( $child ) ) {
				// This is an optimization which avoids an O(n^2) set of
				// array_splice operations.
				$child->parent = null;
			}
			$this->appendChild( $child );
		}
		$elt->children = [];
	}

	/**
	 * Flatten this node and all of its children into a string, as specified
	 * by the HTML serialization specification, and replace this node
	 * in its parent by that string.
	 *
	 * @see __toString()
	 */
	public function flatten() {
		Assert::parameter( $this->parent !== null, '$this', 'must be a child' );
		Assert::parameter( $this->parent !== 'flat', '$this', 'already flat' );
		$idx = array_search( $this, $this->parent->children, true );
		Assert::parameter(
			$idx !== false, '$this', 'must be a child of its parent'
		);
		$flat = "{$this}";
		$this->parent->children[$idx] = $flat;
		$this->parent = 'flat'; # for assertion checking
		return $flat;
	}

	/**
	 * Serialize this node and all of its children to a string, as specified
	 * by the HTML serialization specification.
	 *
	 * @return string The serialization of the BalanceElement
	 * @see https://html.spec.whatwg.org/multipage/syntax.html#serialising-html-fragments
	 */
	public function __toString() {
		$out = "<{$this->localName}{$this->attribs}>";
		if ( !$this->isA( BalanceSets::$emptyElementSet ) ) {
			// flatten children
			foreach ( $this->children as $elt ) {
				$out .= "{$elt}";
			}
			$out .= "</{$this->localName}>";
		} else {
			Assert::invariant(
				count( $this->children ) === 0,
				"Empty elements shouldn't have children."
			);
		}
		return $out;
	}

	# Utility functions on BalanceElements.

	/**
	 * Determine if $this represents a specific HTML tag, is a member of
	 * a tag set, or is equal to another BalanceElement.
	 *
	 * @param BalanceElement|array|string $set The target BalanceElement,
	 *   set (from the BalanceSets class), or string (HTML tag name).
	 * @return bool
	 */
	public function isA( $set ) {
		Assert::parameterType( 'MediaWiki\Tidy\BalanceElement|array|string', $set, '$set' );
		if ( $set instanceof BalanceElement ) {
			return $this === $set;
		} elseif ( is_array( $set ) ) {
			return isset( $set[$this->namespaceURI] ) &&
				isset( $set[$this->namespaceURI][$this->localName] );
		} else {
			# assume this is an HTML element name.
			return $this->isHtml() && $this->localName === $set;
		}
	}

	/**
	 * Determine if $this represents an element in the HTML namespace.
	 *
	 * @return bool
	 */
	public function isHtml() {
		return $this->namespaceURI === BalanceSets::HTML_NAMESPACE;
	}

	/**
	 * Determine if $this represents a MathML text integration point,
	 * as defined in the HTML5 specification.
	 *
	 * @return bool
	 * @see https://html.spec.whatwg.org/multipage/syntax.html#mathml-text-integration-point
	 */
	public function isMathmlTextIntegrationPoint() {
		return $this->isA( BalanceSets::$mathmlTextIntegrationPointSet );
	}

	/**
	 * Determine if $this represents an HTML integration point,
	 * as defined in the HTML5 specification.
	 *
	 * @return bool
	 * @see https://html.spec.whatwg.org/multipage/syntax.html#html-integration-point
	 */
	public function isHtmlIntegrationPoint() {
		if ( $this->isA( BalanceSets::$htmlIntegrationPointSet ) ) {
			return true;
		}
		if (
			$this->namespaceURI === BalanceSets::MATHML_NAMESPACE &&
			$this->localName === 'annotation-xml' &&
			// We rely on Sanitizer::fixTagAttributes having run on $attribs
			// to normalize the form of the tag parameters.
			preg_match( ':(^| )encoding="(text/html|application/xhtml+xml)":i', $this->attribs )
		) {
			return true;
		}
		return false;
	}
}

/**
 * The "stack of open elements" as defined in the HTML5 tree builder
 * spec.  This contains methods to ensure that content (start tags, text)
 * are inserted at the correct place in the output string, and to
 * flatten BalanceElements are they are closed to avoid holding onto
 * a complete DOM tree for the document in memory.
 *
 * The stack defines a PHP iterator to traverse it in "reverse order",
 * that is, the most-recently-added element is visited first in a
 * foreach loop.
 *
 * @ingroup Parser
 * @since 1.27
 * @see https://html.spec.whatwg.org/multipage/syntax.html#the-stack-of-open-elements
 */
class BalanceStack implements IteratorAggregate {
	/**
	 * Backing storage for the stack.
	 * @var array $elements
	 */
	private $elements = [];
	/**
	 * Foster parent mode determines how nodes are inserted into the
	 * stack.
	 * @var bool $fosterParentMode
	 * @see https://html.spec.whatwg.org/multipage/syntax.html#foster-parent
	 */
	public $fosterParentMode = false;

	/**
	 * Create a new BalanceStack with a single BalanceElement on it,
	 * representing the root &lt;html&gt; node.
	 */
	public function __construct() {
		# always a root <html> element on the stack
		array_push(
			$this->elements,
			new BalanceElement( BalanceSets::HTML_NAMESPACE, 'html', '' )
		);
	}

	/**
	 * Return a string representing the output of the tree builder:
	 * all the children of the root &lt;html&gt; node.
	 * @return string
	 */
	public function getOutput() {
		// Don't include the outer '<html>....</html>'
		$out = '';
		foreach ( $this->elements[0]->children as $elt ) {
			$out .= is_string( $elt ) ? $elt : $elt->flatten();
		}
		return $out;
	}

	/**
	 * Insert text at the appropriate place for inserting a node.
	 * @param string $value
	 * @see https://html.spec.whatwg.org/multipage/syntax.html#appropriate-place-for-inserting-a-node
	 */
	public function insertText( $value ) {
		Assert::parameterType( 'string', $value, '$value' );
		if (
			$this->fosterParentMode &&
			$this->currentNode()->isA( BalanceSets::$tableSectionRowSet )
		) {
			$this->fosterParent( $value );
		} else {
			$this->currentNode()->appendChild( $value );
		}
	}

	/**
	 * Insert a BalanceElement at the appropriate place, pushing it
	 * on to the open elements stack.
	 * @param string $namespaceURI The element namespace
	 * @param string $tag The tag name
	 * @param string $attribs Normalized attributes, as a string.
	 * @return BalanceElement
	 * @see https://html.spec.whatwg.org/multipage/syntax.html#insert-a-foreign-element
	 */
	public function insertForeignElement( $namespaceURI, $tag, $attribs ) {
		return $this->insertElement(
			new BalanceElement( $namespaceURI, $tag, $attribs )
		);
	}

	/**
	 * Insert an HTML element at the appropriate place, pushing it on to
	 * the open elements stack.
	 * @param string $tag The tag name
	 * @param string $attribs Normalized attributes, as a string.
	 * @return BalanceElement
	 * @see https://html.spec.whatwg.org/multipage/syntax.html#insert-an-html-element
	 */
	public function insertHTMLElement( $tag, $attribs ) {
		return $this->insertForeignElement(
			BalanceSets::HTML_NAMESPACE, $tag, $attribs
		);
	}

	/**
	 * Insert an element at the appropriate place and push it on to the
	 * open elements stack.
	 * @param BalanceElement $elt
	 * @return BalanceElement
	 * @see https://html.spec.whatwg.org/multipage/syntax.html#appropriate-place-for-inserting-a-node
	 */
	public function insertElement( $elt ) {
		Assert::parameterType( 'MediaWiki\Tidy\BalanceElement', $elt, '$elt' );
		if (
			$this->fosterParentMode &&
			$this->currentNode()->isA( BalanceSets::$tableSectionRowSet )
		) {
			$elt = $this->fosterParent( $elt );
		} else {
			$this->currentNode()->appendChild( $elt );
		}
		Assert::invariant( $elt->parent !== null, "$elt must be in tree" );
		Assert::invariant( $elt->parent !== 'flat', "$elt must not have been previous flattened" );
		array_push( $this->elements, $elt );
		return $elt;
	}

	/**
	 * Determine if the stack has $tag in scope.
	 * @param BalanceElement|array|string $tag
	 * @return bool
	 * @see https://html.spec.whatwg.org/multipage/syntax.html#has-an-element-in-scope
	 */
	public function inScope( $tag ) {
		return $this->inSpecificScope( $tag, BalanceSets::$inScopeSet );
	}

	/**
	 * Determine if the stack has $tag in button scope.
	 * @param BalanceElement|array|string $tag
	 * @return bool
	 * @see https://html.spec.whatwg.org/multipage/syntax.html#has-an-element-in-button-scope
	 */
	public function inButtonScope( $tag ) {
		return $this->inSpecificScope( $tag, BalanceSets::inButtonScopeSet() );
	}

	/**
	 * Determine if the stack has $tag in list item scope.
	 * @param BalanceElement|array|string $tag
	 * @return bool
	 * @see https://html.spec.whatwg.org/multipage/syntax.html#has-an-element-in-list-item-scope
	 */
	public function inListItemScope( $tag ) {
		return $this->inSpecificScope( $tag, BalanceSets::inListItemScopeSet() );
	}

	/**
	 * Determine if the stack has $tag in table scope.
	 * @param BalanceElement|array|string $tag
	 * @return bool
	 * @see https://html.spec.whatwg.org/multipage/syntax.html#has-an-element-in-table-scope
	 */
	public function inTableScope( $tag ) {
		return $this->inSpecificScope( $tag, BalanceSets::$inTableScopeSet );
	}

	/**
	 * Determine if the stack has $tag in a specific scope, $set.
	 * @param BalanceElement|array|string $tag
	 * @param BalanceElement|array|string $set
	 * @return bool
	 * @see https://html.spec.whatwg.org/multipage/syntax.html#has-an-element-in-the-specific-scope
	 */
	public function inSpecificScope( $tag, $set ) {
		foreach ( $this as $elt ) {
			if ( $elt->isA( $tag ) ) {
				return true;
			}
			if ( $elt->isA( $set ) ) {
				return false;
			}
		}
		return false;
	}

	/**
	 * Generate implied end tags.
	 * @param BalanceElement|array|string|null $butnot
	 * @param bool $thorough True if we should generate end tags thoroughly.
	 * @see https://html.spec.whatwg.org/multipage/syntax.html#generate-implied-end-tags
	 */
	public function generateImpliedEndTags( $butnot = null, $thorough = false ) {
		$endTagSet = $thorough ?
			BalanceSets::$thoroughImpliedEndTagsSet :
			BalanceSets::$impliedEndTagsSet;
		while ( $this->length() > 0 ) {
			if ( $butnot !== null && $this->currentNode()->isA( $butnot ) ) {
				break;
			}
			if ( !$this->currentNode()->isA( $endTagSet ) ) {
				break;
			}
			$this->pop();
		}
	}

	/**
	 * Return the current node (the element in the stack with the largest
	 * index).
	 * @return BalanceElement
	 * @see https://html.spec.whatwg.org/multipage/syntax.html#current-node
	 */
	public function currentNode() {
		return $this->node( count( $this->elements ) - 1 );
	}

	/**
	 * Return the adjusted current node.
	 */
	public function adjustedCurrentNode( $fragmentContext ) {
		return ( $fragmentContext && $this->length() === 1 ) ?
			$fragmentContext : $this->currentNode();
	}

	/**
	 * Return an iterator over this stack which visits the current node
	 * first, and the root node last.
	 * @return Iterator
	 */
	public function getIterator() {
		return new ReverseArrayIterator( $this->elements );
	}

	/**
	 * Return the BalanceElement at the given position $idx, where
	 * position 0 represents the root element.
	 * @param int $idx
	 * @return BalanceElement
	 */
	public function node( $idx ) {
		return $this->elements[ $idx ];
	}

	/**
	 * Replace the element at position $idx in the BalanceStack with $elt.
	 * @param int $idx
	 * @param BalanceElement $elt
	 */
	public function replaceAt( $idx, $elt ) {
		Assert::parameterType( 'MediaWiki\Tidy\BalanceElement', $elt, '$elt' );
		Assert::precondition(
			$this->elements[$idx]->parent !== 'flat',
			'Replaced element should not have already been flattened.'
		);
		Assert::precondition(
			$elt->parent !== 'flat',
			'New element should not have already been flattened.'
		);
		$this->elements[$idx] = $elt;
	}

	/**
	 * Return the position of the given BalanceElement, set, or
	 * HTML tag name string in the BalanceStack.
	 * @param BalanceElement|array|string $tag
	 * @return int
	 */
	public function indexOf( $tag ) {
		for ( $i = count( $this->elements ) - 1; $i >= 0; $i-- ) {
			if ( $this->elements[$i]->isA( $tag ) ) {
				return $i;
			}
		}
		return -1;
	}

	/**
	 * Return the number of elements currently in the BalanceStack.
	 * @return int
	 */
	public function length() {
		return count( $this->elements );
	}

	/**
	 * Remove the current node from the BalanceStack, flattening it
	 * in the process.
	 */
	public function pop() {
		$elt = array_pop( $this->elements );
		$elt->flatten();
	}

	/**
	 * Remove all nodes up to and including position $idx from the
	 * BalanceStack, flattening them in the process.
	 * @param int $idx
	 */
	public function popTo( $idx ) {
		while ( $this->length() > $idx ) {
			$this->pop();
		}
	}

	/**
	 * Pop elements off the stack up to and including the first
	 * element with the specified HTML tagname (or matching the given
	 * set).
	 * @param BalanceElement|array|string $tag
	 */
	public function popTag( $tag ) {
		while ( $this->length() > 0 ) {
			if ( $this->currentNode()->isA( $tag ) ) {
				$this->pop();
				break;
			}
			$this->pop();
		}
	}

	/**
	 * Pop elements off the stack *not including* the first element
	 * in the specified set.
	 * @param BalanceElement|array|string $set
	 */
	public function clearToContext( $set ) {
		// Note that we don't loop to 0. Never pop the <html> elt off.
		while ( $this->length() > 1 ) {
			if ( $this->currentNode()->isA( $set ) ) {
				break;
			}
			$this->pop();
		}
	}

	/**
	 * Remove the given $elt from the BalanceStack, optionally
	 * flattening it in the process.
	 * @param BalanceElement $elt The element to remove.
	 * @param bool $flatten Whether to flatten the removed element.
	 */
	public function removeElement( $elt, $flatten = true ) {
		Assert::parameterType( 'MediaWiki\Tidy\BalanceElement', $elt, '$elt' );
		Assert::parameter(
			$elt->parent !== 'flat',
			'$elt',
			'$elt should not already have been flattened.'
		);
		Assert::parameter(
			$elt->parent->parent !== 'flat',
			'$elt',
			'The parent of $elt should not already have been flattened.'
		);
		$idx = array_search( $elt, $this->elements, true );
		Assert::parameter( $idx !== false, '$elt', 'must be in stack' );
		array_splice( $this->elements, $idx, 1 );
		if ( $flatten ) {
			// serialize $elt into its parent
			// otherwise, it will eventually serialize when the parent
			// is serialized, we just hold onto the memory for its
			// tree of objects a little longer.
			$elt->flatten();
		}
		Assert::postcondition(
			array_search( $elt, $this->elements, true ) === false,
			'$elt should no longer be in open elements stack'
		);
	}

	/**
	 * Find $a in the BalanceStack and insert $b after it.
	 * @param BalanceElement $a
	 * @param BalanceElement $b
	 */
	public function insertAfter( $a, $b ) {
		Assert::parameterType( 'MediaWiki\Tidy\BalanceElement', $a, '$a' );
		Assert::parameterType( 'MediaWiki\Tidy\BalanceElement', $b, '$b' );
		$idx = $this->indexOf( $a );
		Assert::parameter( $idx !== false, '$a', 'must be in stack' );
		array_splice( $this->elements, $idx + 1, 0, [ $b ] );
	}

	# Fostering and adoption.

	/**
	 * Foster parent the given $elt in the stack of open elements.
	 * @param BalanceElement|string $elt
	 * @see https://html.spec.whatwg.org/multipage/syntax.html#foster-parent
	 */
	private function fosterParent( $elt ) {
		Assert::parameterType( 'MediaWiki\Tidy\BalanceElement|string', $elt, '$elt' );
		$lastTable = $this->indexOf( 'table' );
		$lastTemplate = $this->indexOf( 'template' );
		$parent = null;
		$before = null;

		if ( $lastTemplate >= 0 && ( $lastTable < 0 || $lastTemplate > $lastTable ) ) {
			$parent = $this->elements[$lastTemplate];
		} elseif ( $lastTable >= 0 ) {
			$parent = $this->elements[$lastTable]->parent;
			# Assume all tables have parents, since we're not running scripts!
			Assert::invariant(
				$parent !== null, "All tables should have parents"
			);
			$before = $this->elements[$lastTable];
		} else {
			$parent = $this->elements[0]; // the `html` element.
		}
		if ( $before ) {
			$parent->insertBefore( $before, $elt );
		} else {
			$parent->appendChild( $elt );
		}
		return $elt;
	}

	/**
	 * Run the "adoption agency algoritm" (AAA) for the given subject
	 * tag name.
	 * @param string $tag The subject tag name.
	 * @param BalanceActiveFormattingElements $afe The current
	 *   active formatting elements list.
	 * @return true if the adoption agency algorithm "did something", false
	 *   if more processing is required by the caller.
	 * @see https://html.spec.whatwg.org/multipage/syntax.html#adoption-agency-algorithm
	 */
	public function adoptionAgency( $tag, $afe ) {
		// If the current node is an HTML element whose tag name is subject,
		// and the current node is not in the list of active formatting
		// elements, then pop the current node off the stack of open
		// elements and abort these steps.
		if (
			$this->currentNode()->isA( $tag ) &&
			$afe->indexOf( $this->currentNode() ) < 0
		) {
			$this->pop();
			return true; // no more handling required
		}

		// Let outer loop counter be zero.
		$outer = 0;

		// Outer loop: If outer loop counter is greater than or
		// equal to eight, then abort these steps.
		while ( $outer < 8 ) {
			// Increment outer loop counter by one.
			$outer++;

			// Let the formatting element be the last element in the list
			// of active formatting elements that: is between the end of
			// the list and the last scope marker in the list, if any, or
			// the start of the list otherwise, and has the same tag name
			// as the token.
			$fmtelt = $afe->findElementByTag( $tag );

			// If there is no such node, then abort these steps and instead
			// act as described in the "any other end tag" entry below.
			if ( !$fmtelt ) {
				return false; // false means handle by the default case
			}

			// Otherwise, if there is such a node, but that node is not in
			// the stack of open elements, then this is a parse error;
			// remove the element from the list, and abort these steps.
			$index = $this->indexOf( $fmtelt );
			if ( $index < 0 ) {
				$afe->remove( $fmtelt );
				return true;   // true means no more handling required
			}

			// Otherwise, if there is such a node, and that node is also in
			// the stack of open elements, but the element is not in scope,
			// then this is a parse error; ignore the token, and abort
			// these steps.
			if ( !$this->inScope( $fmtelt ) ) {
				return true;
			}

			// Let the furthest block be the topmost node in the stack of
			// open elements that is lower in the stack than the formatting
			// element, and is an element in the special category. There
			// might not be one.
			$furthestblock = null;
			$furthestblockindex = -1;
			$stacklen = $this->length();
			for ( $i = $index+1; $i < $stacklen; $i++ ) {
				if ( $this->node( $i )->isA( BalanceSets::$specialSet ) ) {
					$furthestblock = $this->node( $i );
					$furthestblockindex = $i;
					break;
				}
			}

			// If there is no furthest block, then the UA must skip the
			// subsequent steps and instead just pop all the nodes from the
			// bottom of the stack of open elements, from the current node
			// up to and including the formatting element, and remove the
			// formatting element from the list of active formatting
			// elements.
			if ( !$furthestblock ) {
				$this->popTag( $fmtelt );
				$afe->remove( $fmtelt );
				return true;
			} else {
				// Let the common ancestor be the element immediately above
				// the formatting element in the stack of open elements.
				$ancestor = $this->node( $index-1 );

				// Let a bookmark note the position of the formatting
				// element in the list of active formatting elements
				// relative to the elements on either side of it in the
				// list.
				$BOOKMARK = new BalanceElement( '[bookmark]', '[bookmark]', '' );
				$afe->insertAfter( $fmtelt, $BOOKMARK );

				// Let node and last node be the furthest block.
				$node = $furthestblock;
				$lastnode = $furthestblock;
				$nodeindex = $furthestblockindex;
				$nodeafeindex = -1;

				// Let inner loop counter be zero.
				$inner = 0;

				while ( true ) {

					// Increment inner loop counter by one.
					$inner++;

					// Let node be the element immediately above node in
					// the stack of open elements, or if node is no longer
					// in the stack of open elements (e.g. because it got
					// removed by this algorithm), the element that was
					// immediately above node in the stack of open elements
					// before node was removed.
					$node = $this->node( --$nodeindex );

					// If node is the formatting element, then go
					// to the next step in the overall algorithm.
					if ( $node === $fmtelt ) break;

					// If the inner loop counter is greater than three and node
					// is in the list of active formatting elements, then remove
					// node from the list of active formatting elements.
					$nodeafeindex = $afe->indexOf( $node );
					if ( $inner > 3 && $nodeafeindex !== -1 ) {
						$afe->remove( $node );
						$nodeafeindex = -1;
					}

					// If node is not in the list of active formatting
					// elements, then remove node from the stack of open
					// elements and then go back to the step labeled inner
					// loop.
					if ( $nodeafeindex === -1 ) {
						// Don't flatten here, since we're about to relocate
						// parts of this $node.
						$this->removeElement( $node, false );
						continue;
					}

					// Create an element for the token for which the
					// element node was created with common ancestor as
					// the intended parent, replace the entry for node
					// in the list of active formatting elements with an
					// entry for the new element, replace the entry for
					// node in the stack of open elements with an entry for
					// the new element, and let node be the new element.
					$newelt = $afe->cloneAt( $nodeafeindex ); // XXX
					$afe->replace( $node, $newelt );
					$this->replaceAt( $nodeindex, $newelt );
					$node = $newelt;

					// If last node is the furthest block, then move the
					// aforementioned bookmark to be immediately after the
					// new node in the list of active formatting elements.
					if ( $lastnode === $furthestblock ) {
						$afe->remove( $BOOKMARK );
						$afe->insertAfter( $newelt, $BOOKMARK );
					}

					// Insert last node into node, first removing it from
					// its previous parent node if any.
					$node->appendChild( $lastnode );

					// Let last node be node.
					$lastnode = $node;
				}

				// If the common ancestor node is a table, tbody, tfoot,
				// thead, or tr element, then, foster parent whatever last
				// node ended up being in the previous step, first removing
				// it from its previous parent node if any.
				if (
					$this->fosterParentMode &&
					$ancestor->isA( BalanceSets::$tableSectionRowSet )
				) {
					$this->fosterParent( $lastnode );
				} else {
					// Otherwise, append whatever last node ended up being in
					// the previous step to the common ancestor node, first
					// removing it from its previous parent node if any.
					$ancestor->appendChild( $lastnode );
				}

				// Create an element for the token for which the
				// formatting element was created, with furthest block
				// as the intended parent.
				$newelt2 = $afe->cloneAt( $afe->indexOf( $fmtelt ) );

				// Take all of the child nodes of the furthest block and
				// append them to the element created in the last step.
				$newelt2->adoptChildren( $furthestblock );

				// Append that new element to the furthest block.
				$furthestblock->appendChild( $newelt2 );

				// Remove the formatting element from the list of active
				// formatting elements, and insert the new element into the
				// list of active formatting elements at the position of
				// the aforementioned bookmark.
				$afe->remove( $fmtelt );
				$afe->replace( $BOOKMARK, $newelt2 );

				// Remove the formatting element from the stack of open
				// elements, and insert the new element into the stack of
				// open elements immediately below the position of the
				// furthest block in that stack.
				$this->removeElement( $fmtelt );
				$this->insertAfter( $furthestblock, $newelt2 );
			}
		}

		return true;
	}

	/**
	 * Return the contents of the open elements stack as a string for
	 * debugging.
	 * @return string
	 */
	public function __toString() {
		$r = [];
		foreach ( $this->elements as $elt ) {
			array_push( $r, $elt->localName );
		}
		return implode( $r, ' ' );
	}
}

/**
 * The list of active formatting elements, which is used to handle
 * mis-nested formatting element tags in the HTML5 tree builder
 * specification.
 *
 * @ingroup Parser
 * @since 1.27
 * @see https://html.spec.whatwg.org/multipage/syntax.html#list-of-active-formatting-elements
 */
class BalanceActiveFormattingElements {
	private $elemList = [];
	private $attribList = [];
	private static $MARKER = '|';

	public function insertMarker() {
		$this->elemList[] = self::$MARKER;
		$this->attribList[] = self::$MARKER;
	}

	public function push( $elt, $attribs ) {
		// "Noah's Ark clause" -- if there are already three copies of
		// this element before we encounter a marker, then drop the last
		// one.
		$count = 0;
		for ( $i = count( $this->elemList ) - 1; $i >= 0; $i-- ) {
			if ( $this->elemList[$i] === self::$MARKER ) {
				break;
			}
			// Note that we rely on Sanitizer::fixTagAttributes having run
			// previously with the $sorted option true.  The attributes are
			// thus canonicalized, which allows us to compare $attribs with
			// a simple string compare.
			if (
				$this->elemList[$i]->localName === $elt->localName &&
				$this->attribList[$i] === $elt->attribs
			) {
				$count++;
				if ( $count === 3 ) {
					array_splice( $this->elemList, $i, 1 );
					array_splice( $this->attribList, $i, 1 );
					break;
				}
			}
		}
		// Now push the new element onto the list.
		$this->elemList[] = $elt;
		// Spec says we have to clone the attribs, in case the element's
		// attributes are later modified.
		$this->attribList[] = $elt->attribs;
	}

	public function clearToMarker() {
		# This is deliberately >0 not >=0, since it doesn't matter if element
		# 0 is the marker, we clear the whole list in that case regardless.
		for ( $i = count( $this->elemList ) - 1; $i > 0; $i-- ) {
			if ( $this->elemList[$i] === self::$MARKER ) {
				break;
			}
		}
		array_splice( $this->elemList, $i );
		array_splice( $this->attribList, $i );
	}

	/**
	 * Find and return the last element with the specified tag between the
	 * end of the list and the last marker on the list.
	 * Used when parsing &lt;a&gt; "in body mode".
	 */
	public function findElementByTag( $tag ) {
		for ( $i = count( $this->elemList ) - 1; $i >= 0; $i-- ) {
			$elt = $this->elemList[$i];
			if ( $elt === self::$MARKER ) {
				break;
			}
			if ( $elt->localName === $tag ) {
				return $elt;
			}
		}
		return null;
	}

	public function indexOf( $elt ) {
		for ( $i = count( $this->elemList ) - 1; $i >= 0; $i-- ) {
			if ( $this->elemList[$i] === $elt ) {
				return $i;
			}
		}
		return -1;
	}

	/**
	 * Find the element $elt in the list and remove it.
	 * Used when parsing &lt;a&gt; in body mode.
	 */
	public function remove( $elt ) {
		$idx = $this->indexOf( $elt );
		Assert::parameter( $idx >= 0, '$elt', 'should be present in afe list' );
		array_splice( $this->elemList, $idx, 1 );
		array_splice( $this->attribList, $idx, 1 );
	}

	/**
	 * Find element $a in the list and replace it with element $b,
	 * optionally replacing the stored attributes as well with $attribs.
	 */
	public function replace( $a, $b, $attribs=null ) {
		$idx = $this->indexOf( $a );
		if ( $idx >= 0 ) {
			$this->elemList[ $idx ] = $b;
			if ( $attribs !== null ) {
				$this->attribList[  $idx ] = $attribs;
			}
		}
	}

	/**
	 * Find $a in the list and insert $b after it.
	 * This is only used for insert a bookmark object, so the
	 * $this->attribList contents don't really matter.
	 */
	public function insertAfter( $a, $b ) {
		$idx = $this->indexOf( $a );
		if ( $idx >= 0 ) {
			array_splice( $this->elemList, $idx, 0, [ $b ] );
			array_splice( $this->attribList, $idx, 0, [ '' ] );
		}
	}

	/**
	 * Make a copy of element $idx on the list of active formatting
	 * elements, using its original attributes not current attributes.
	 * (In full HTML spec, current attributes could have been modified
	 * by a script.)
	 */
	public function cloneAt( $idx ) {
		$node = $this->elemList[$idx];
		$attribs = $this->attribList[$idx];
		return new BalanceElement(
			$node->namespaceURI, $node->localName, $attribs
		);
	}

	// @codingStandardsIgnoreStart Generic.Files.LineLength.TooLong
	/**
	 * Reconstruct the active formatting elements.
	 * @param BalanceStack $stack The open elements stack
	 * @see https://html.spec.whatwg.org/multipage/syntax.html#reconstruct-the-active-formatting-elements
	 */
	// @codingStandardsIgnoreEnd
	public function reconstruct( $stack ) {
		if ( empty( $this->elemList ) ) {
			return;
		}
		$len = count( $this->elemList );
		$entry = $this->elemList[$len - 1];
		// If the last is a marker, do nothing.
		if ( $entry === self::$MARKER ) {
			return;
		}
		// Or if it is an open element, do nothing.
		if ( $stack->indexOf( $entry ) >= 0 ) {
			return;
		}

		// Loop backward through the list until we find a marker or an
		// open element, and then move forward one from there.
		for ( $i = $len - 2; $i >= 0; $i-- ) {
			$entry = $this->elemList[$i];
			if ( $entry === self::$MARKER ) {
				break;
			}
			if ( $stack->indexOf( $entry ) >= 0 ) {
				break;
			}
		}

		// Now loop forward, starting from the element after the current
		// one, recreating formatting elements and pushing them back onto
		// the list of open elements
		for ( $i++; $i < $len; $i++ ) {
			$this->elemList[$i] = $stack->insertHTMLElement(
				$this->elemList[$i]->localName,
				$this->attribList[$i]
			);
		}
	}
}

/**
 * An implementation of the tree building portion of the HTML5 parsing
 * spec.
 *
 * This is used to balance and tidy output so that the result can
 * always be cleanly serialized/deserialized by an HTML5 parser.  It
 * does *not* guarantee "conforming" output -- the HTML5 spec contains
 * a number of constraints which are not enforced by the HTML5 parsing
 * process.  But the result will be free of gross errors: misnested or
 * unclosed tags, for example, and will be unchanged by spec-complient
 * parsing followed by serialization.
 *
 * The tree building stage is structured as a state machine.
 * When comparing the implementation to
 * https://www.w3.org/TR/html5/syntax.html#tree-construction
 * note that each state is implemented as a function with a
 * name ending in `Mode` (because the HTML spec refers to them
 * as insertion modes).  The current insertion mode is held by
 * the $parseMode property.
 *
 * The following simplifications have been made:
 * - We handle body content only (ie, we start `in body`.)
 * - The document is never in "quirks mode".
 * - All occurrences of < and > have been entity escaped, so we
 *   can parse tags by simply splitting on those two characters.
 *   Similarly, all attributes have been "cleaned" and are double-quoted
 *   and escaped.
 * - All comments and null characters are assumed to have been removed.
 * - We don't alter linefeeds after <pre>/<listing>.
 * - The following elements are disallowed: <html>, <head>, <body>, <frameset>,
 *   <form>, <frame>, <plaintext>, <isindex>, <textarea>, <xmp>, <iframe>,
 *   <noembed>, <noscript>, <select>, <script>, <title>.  As a result,
 *   further simplifications can be made:
 *   - `frameset-ok` is not tracked.
 *   - `form element pointer` is not tracked.
 *   - `head element pointer` is not tracked (but presumed non-null)
 *   - Tokenizer has only a single mode.
 *
 *   We generally mark places where we omit cases from the spec due to
 *   disallowed elements with a comment: `# OMITTED: <element-name>`.
 *
 *   The HTML spec keeps a flag during the parsing process to track
 *   whether or not a "parse error" has been encountered.  We don't
 *   bother to track that flag, we just implement the error-handling
 *   process as specified.
 *
 * @ingroup Parser
 * @since 1.27
 * @see https://html.spec.whatwg.org/multipage/syntax.html#tree-construction
 */
class Balancer {
	private $parseMode;
	private $bitsIterator;
	private $allowedHtmlElements;
	private $afe;
	private $stack;
	private $strict;

	private $textIntegrationMode = false;
	private $pendingTableText;
	private $originalInsertionMode;
	private $fragmentContext;

	/**
	 * Create a new Balancer.
	 * @param array $config Balancer configuration.  Includes:
	 *     'strict' : boolean, defaults to false.
	 *         When true, enforces syntactic constraints on input:
	 *         all non-tag '<' must be escaped, all attributes must be
	 *         separated by a single space and double-quoted.  This is
	 *         consistent with the output of the Sanitizer.
	 *     'allowedHtmlElements' : array, defaults to null.
	 *         When present, the keys of this associative array give
	 *         the acceptable HTML tag names.  When not present, no
	 *         tag sanitization is done.
	 */
	public function __construct( array $config ) {
		$config = $config + [
			'strict' => false,
			'allowedHtmlElements' => null,
		];
		$this->allowedHtmlElements = $config['allowedHtmlElements'];
		$this->strict = $config['strict'];
		if ( $this->allowedHtmlElements !== null ) {
			# Sanity check!
			$bad = array_uintersect_assoc(
				$this->allowedHtmlElements,
				BalanceSets::$unsupportedSet[BalanceSets::HTML_NAMESPACE],
				function( $a, $b ) {
					// Ignore the values (just intersect the keys) by saying
					// all values are equal to each other.
					return 0;
				}
			);
			if ( count( $bad ) > 0 ) {
				$badstr = implode( array_keys( $bad ), ',' );
				throw new ParameterAssertionException(
					'$config',
					'Balance attempted with sanitization including ' .
					"unsupported elements: {$badstr}"
				);
			}
		}
	}

	/**
	 * Return a balanced HTML string for the HTML fragment given by $text,
	 * subject to the caveats listed in the class description.  The result
	 * will typically be idempotent -- that is, rebalancing the output
	 * would result in no change.
	 *
	 * @param string $text The markup to be balanced
	 * @param callable $processingCallback Callback to do any variable or
	 *   parameter replacements in HTML attributes values
	 * @param array|bool $processingArgs Arguments for the processing callback
	 * @return string The balanced markup
	 */
	public function balance( $text, $processingCallback = null, $processingArgs = [] ) {
		$this->parseMode = 'inBodyMode';
		$this->bitsIterator = new ExplodeIterator( '<', $text );
		$this->afe = new BalanceActiveFormattingElements();
		$this->stack = new BalanceStack();
		$this->processingCallback = $processingCallback;
		$this->processingArgs = $processingArgs;

		# The stack is constructed with an <html> element already on it.
		# Set this up as a fragment parsed with <body> as the context.
		$this->fragmentContext =
			new BalanceElement( BalanceSets::HTML_NAMESPACE, 'body', '' );
		$this->resetInsertionMode();

		// First element is text not tag
		$x = $this->bitsIterator->current();
		$this->bitsIterator->next();
		$this->insertToken( 'text', str_replace( '>', '&gt;', $x ) );
		// Now process each tag.
		while ( $this->bitsIterator->valid() ) {
			$this->advance();
		}
		$this->insertToken( 'eof', null );
		$result = $this->stack->getOutput();
		// Free memory before returning.
		$this->bitsIterator = null;
		$this->afe = null;
		$this->stack = null;
		$this->fragmentContext = null;
		return $result;
	}

	/**
	 * Pass a token to the tree builder.  The $token will be one of the
	 * strings "tag", "endtag", or "text".
	 */
	private function insertToken( $token, $value, $attribs = null, $selfclose = false ) {
		// validate tags against $unsupportedSet
		if ( $token === 'tag' || $token === 'endtag' ) {
			if ( isset( BalanceSets::$unsupportedSet[BalanceSets::HTML_NAMESPACE][$value] ) ) {
				# As described in "simplifications" above, these tags are
				# not supported in the balancer.
				Assert::invariant(
					!$this->strict,
					"Unsupported $token <$value> found."
				);
				return false;
			}
		} elseif ( $token === 'text' && $value === '' ) {
			# Don't actually inject the empty string as a text token.
			return true;
		}
		// Some hoops we have to jump through
		$adjusted = $this->stack->adjustedCurrentNode( $this->fragmentContext );

		$isForeign = true;
		if (
			$this->stack->length() === 0 ||
			$adjusted->isHtml() ||
			$token === 'eof'
		) {
			$isForeign = false;
		} elseif ( $adjusted->isMathmlTextIntegrationPoint() ) {
			if ( $token === 'text' ) {
				$isForeign = false;
			} elseif (
				$token === 'tag' &&
				$value !== 'mglyph' && $value !== 'malignmark'
			) {
				$isForeign = false;
			}
		} elseif (
			$adjusted->namespaceURI === BalanceSets::MATHML_NAMESPACE &&
			$adjusted->localName === 'annotation-xml' &&
			$token === 'tag' && $value === 'svg'
		) {
			$isForeign = false;
		} elseif (
			$adjusted->isHtmlIntegrationPoint() &&
			( $token === 'tag' || $token === 'text' )
		) {
			$isForeign = false;
		}
		if ( $isForeign ) {
			return $this->insertForeignToken( $token, $value, $attribs, $selfclose );
		} else {
			$func = $this->parseMode;
			return $this->$func( $token, $value, $attribs, $selfclose );
		}
	}

	private function insertForeignToken( $token, $value, $attribs = null, $selfclose = false ) {
		if ( $token === 'text' ) {
			$this->stack->insertText( $value );
			return true;
		} elseif ( $token === 'tag' ) {
			switch ( $value ) {
			case 'font':
				// We rely on Sanitizer::fixTagAttributes having run on $attribs
				// to normalize the form of the tag parameters.
				if ( !preg_match( '/(^| )(color|face|size)="/i', $attribs ) ) {
					break;
				}
				/* otherwise, fall through */
			case 'b':
			case 'big':
			case 'blockquote':
			case 'body':
			case 'br':
			case 'center':
			case 'code':
			case 'dd':
			case 'div':
			case 'dl':
			case 'dt':
			case 'em':
			case 'embed':
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
			case 'head':
			case 'hr':
			case 'i':
			case 'img':
			case 'li':
			case 'listing':
			case 'menu':
			case 'meta':
			case 'nobr':
			case 'ol':
			case 'p':
			case 'pre':
			case 'ruby':
			case 's':
			case 'small':
			case 'span':
			case 'strong':
			case 'strike':
			case 'sub':
			case 'sup':
			case 'table':
			case 'tt':
			case 'u':
			case 'ul':
			case 'var':
				if ( $this->fragmentContext ) {
					break;
				}
				while ( true ) {
					$this->stack->pop();
					$node = $this->stack->currentNode();
					if (
						$node->isMathmlTextIntegrationPoint() ||
						$node->isHtmlIntegrationPoint() ||
						$node->isHtml()
					) {
						break;
					}
				}
				return $this->insertToken( $token, $value, $attribs, $selfclose );
			}
			// "Any other start tag"
			$adjusted = ( $this->fragmentContext && $this->stack->length()===1 ) ?
				$this->fragmentContext : $this->stack->currentNode();
			$this->stack->insertForeignElement(
				$adjusted->namespaceURI, $value, $attribs
			);
			if ( $selfclose ) {
				$this->stack->pop();
			}
			return true;
		} elseif ( $token === 'endtag' ) {
			$first = true;
			foreach ( $this->stack as $i => $node ) {
				if ( $node->isHtml() && !$first ) {
					// process the end tag as HTML
					$func = $this->parseMode;
					return $this->$func( $token, $value, $attribs, $selfclose );
				} elseif ( $i === 0 ) {
					return true;
				} elseif ( $node->localName === $value ) {
					$this->stack->popTag( $node );
					return true;
				}
				$first = false;
			}
		}
	}

	/**
	 * Grab the next "token" from $bitsIterator.  This is either a open/close
	 * tag or text, depending on whether the Sanitizer approves.
	 */
	private function advance() {
		$x = $this->bitsIterator->current();
		$this->bitsIterator->next();
		$regs = [];
		# $slash: Does the current element start with a '/'?
		# $t: Current element name
		# $attribs: String between element name and >
		# $brace: Ending '>' or '/>'
		# $rest: Everything until the next element from the $bitsIterator
		if ( preg_match( Sanitizer::ELEMENT_BITS_REGEX, $x, $regs ) ) {
			list( /* $qbar */, $slash, $t, $attribs, $brace, $rest ) = $regs;
			$t = strtolower( $t );
			if ( $this->strict ) {
				/* Verify that attributes are all properly double-quoted */
				Assert::invariant(
					preg_match(
						'/^( [:_A-Z0-9][-.:_A-Z0-9]*="[^"]*")*[ ]*$/i', $attribs
					),
					"Bad attribute string found"
				);
			}
		} else {
			Assert::invariant(
				!$this->strict, "< found which does not start a valid tag"
			);
			$slash = $t = $attribs = $brace = $rest = null;
		}
		$goodtag = $t;
		$sanitize = $this->allowedHtmlElements !== null;
		if ( $sanitize ) {
			$goodtag = $t && isset( $this->allowedHtmlElements[$t] );
		}
		if ( $goodtag ) {
			if ( is_callable( $this->processingCallback ) ) {
				call_user_func_array( $this->processingCallback, [ &$attribs, $this->processingArgs ] );
			}
			if ( $sanitize ) {
				$goodtag = Sanitizer::validateTag( $attribs, $t );
			}
		}
		if ( $goodtag ) {
			if ( $sanitize ) {
				$newattribs = Sanitizer::fixTagAttributes( $attribs, $t, true );
			} else {
				$decoded = Sanitizer::decodeTagAttributes( $attribs );
				ksort( $decoded );
				$newattribs = Sanitizer::safeEncodeTagAttributes( $decoded );
			}
			$goodtag = $this->insertToken(
				$slash ? 'endtag' : 'tag', $t, $newattribs, $brace === '/>'
			);
		}
		if ( $goodtag ) {
			$rest = str_replace( '>', '&gt;', $rest );
			$this->insertToken( 'text', str_replace( '>', '&gt;', $rest ) );
		} else {
			# bad tag; serialize entire thing as text.
			$this->insertToken( 'text', '&lt;' . str_replace( '>', '&gt;', $x ) );
		}
	}

	private function switchMode( $mode ) {
		Assert::parameter(
			substr( $mode, -4 )==='Mode', '$mode', 'should end in Mode'
		);
		$oldMode = $this->parseMode;
		$this->parseMode = $mode;
		return $oldMode;
	}

	private function switchModeAndReprocess( $mode, $token, $value, $attribs, $selfclose ) {
		$this->switchMode( $mode );
		return $this->insertToken( $token, $value, $attribs, $selfclose );
	}

	private function resetInsertionMode() {
		$last = false;
		foreach ( $this->stack as $i => $node ) {
			if ( $i === 0 ) {
				$last = true;
				if ( $this->fragmentContext ) {
					$node = $this->fragmentContext;
				}
			}
			if ( $node->isHtml() ) {
				switch ( $node->localName ) {
				# OMITTED: <select>
				/*
				case 'select':
					$stacklen = $this->stack->length();
					for ( $j = $i + 1; $j < $stacklen-1; $j++ ) {
						$ancestor = $this->stack->node( $stacklen-$j-1 );
						if ( $ancestor->isA( 'template' ) ) {
							break;
						}
						if ( $ancestor->isA( 'table' ) ) {
							$this->switchMode( 'inSelectInTableMode' );
							return;
						}
					}
					$this->switchMode( 'inSelectMode' );
					return;
				*/
				case 'tr':
					$this->switchMode( 'inRowMode' );
					return;
				case 'tbody':
				case 'tfoot':
				case 'thead':
					$this->switchMode( 'inTableBodyMode' );
					return;
				case 'caption':
					$this->switchMode( 'inCaptionMode' );
					return;
				case 'colgroup':
					$this->switchMode( 'inColumnGroupMode' );
					return;
				case 'table':
					$this->switchMode( 'inTableMode' );
					return;
				case 'template':
					$this->switchMode(
						array_slice( $this->templateInsertionModes, -1 )[0]
					);
					return;
				case 'body':
					$this->switchMode( 'inBodyMode' );
					return;
				# OMITTED: <frameset>
				# OMITTED: <html>
				# OMITTED: <head>
				default:
					if ( !$last ) {
						# OMITTED: <head>
						if ( $node->isA( BalanceSets::$tableCellSet ) ) {
							$this->switchMode( 'inCellMode' );
							return;
						}
					}
				}
			}
			if ( $last ) {
				$this->switchMode( 'inBodyMode' );
				return;
			}
		}
	}

	private function stopParsing() {
		# Most of the spec methods are inapplicable, other than step 2:
		# "pop all the nodes off the stack of open elements".
		# We're going to keep the top-most <html> element on the stack, though.
		while ( $this->stack->length() > 1 ) {
			$this->stack->pop();
		}
	}

	private function parseRawText( $value, $attribs = null ) {
		$this->stack->insertHTMLElement( $value, $attribs );
		// XXX switch tokenizer to rawtext state?
		$this->originalInsertionMode = $this->switchMode( 'inTextMode' );
		return true;
	}

	private function inTextMode( $token, $value, $attribs = null, $selfclose = false ) {
		if ( $token === 'text' ) {
			$this->stack->insertText( $value );
			return true;
		} elseif ( $token === 'eof' ) {
			$this->stack->pop();
			return $this->switchModeAndReprocess(
				$this->originalInsertionMode, $token, $value, $attribs, $selfclose
			);
		} elseif ( $token === 'endtag' ) {
			$this->stack->pop();
			$this->switchMode( $this->originalInsertionMode );
			return true;
		}
		return true;
	}

	private function inHeadMode( $token, $value, $attribs = null, $selfclose = false ) {
		if ( $token === 'text' ) {
			if ( preg_match( '/^[\x09\x0A\x0C\x0D\x20]+/', $value, $matches ) ) {
				$this->stack->insertText( $matches[0] );
				$value = substr( $value, strlen( $matches[0] ) );
			}
			if ( strlen( $value ) === 0 ) {
				return true; // All text handled.
			}
			// Fall through to handle non-whitespace below.
		} elseif ( $token === 'tag' ) {
			switch ( $value ) {
			# OMITTED: <html>
			case 'base':
			case 'basefont':
			case 'bgsound':
			case 'link':
				$this->stack->insertHTMLElement( $value, $attribs );
				$this->stack->pop();
				return true;
			# OMITTED: <title>
			# OMITTED: <noscript>
			case 'noframes':
			case 'style':
				return $this->parseRawText( $value, $attribs );
			# OMITTED: <script>
			case 'template':
				$this->stack->insertHTMLElement( $value, $attribs );
				$this->afe->insertMarker();
				# OMITTED: frameset_ok
				$this->switchMode( 'inTemplateMode' );
				$this->templateInsertionModes[] = $this->parseMode;
				return true;
			# OMITTED: <head>
			}
		} elseif ( $token === 'endtag' ) {
			switch ( $value ) {
			# OMITTED: <head>
			# OMITTED: <body>
			# OMITTED: <html>
			case 'br':
				break; // handle at the bottom of the function
			case 'template':
				if ( $this->stack->indexOf( $value ) < 0 ) {
					return true; // Ignore the token.
				}
				$this->stack->generateImpliedEndTags( null, true /* thorough */ );
				$this->stack->popTag( $value );
				$this->afe->clearToMarker();
				array_pop( $this->templateInsertionModes );
				$this->resetInsertionMode();
				return true;
			default:
				// ignore any other end tag
				return true;
			}
		}

		// If not handled above
		$this->inHeadMode( 'endtag', 'head' ); // synthetic </head>
		// Then redo this one
		return $this->insertToken( $token, $value, $attribs, $selfclose );
	}

	private function inBodyMode( $token, $value, $attribs = null, $selfclose = false ) {
		if ( $token === 'text' ) {
			$this->afe->reconstruct( $this->stack );
			$this->stack->insertText( $value );
			return true;
		} elseif ( $token === 'eof' ) {
			if ( !empty( $this->templateInsertionModes ) ) {
				return $this->inTemplateMode( $token, $value, $attribs, $selfclose );
			}
			$this->stopParsing();
			return true;
		} elseif ( $token === 'tag' ) {
			switch ( $value ) {
			# OMITTED: <html>
			case 'base':
			case 'basefont':
			case 'bgsound':
			case 'link':
			case 'meta':
			case 'noframes':
			# OMITTED: <script>
			case 'style':
			case 'template':
			# OMITTED: <title>
				return $this->inHeadMode( $token, $value, $attribs, $selfclose );
			# OMITTED: <body>
			# OMITTED: <frameset>

			case 'address':
			case 'article':
			case 'aside':
			case 'blockquote':
			case 'center':
			case 'details':
			case 'dialog':
			case 'dir':
			case 'div':
			case 'dl':
			case 'fieldset':
			case 'figcaption':
			case 'figure':
			case 'footer':
			case 'header':
			case 'hgroup':
			case 'main':
			case 'menu':
			case 'nav':
			case 'ol':
			case 'p':
			case 'section':
			case 'summary':
			case 'ul':
				if ( $this->stack->inButtonScope( 'p' ) ) {
					$this->inBodyMode( 'endtag', 'p' );
				}
				$this->stack->insertHTMLElement( $value, $attribs );
				return true;

			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				if ( $this->stack->inButtonScope( 'p' ) ) {
					$this->inBodyMode( 'endtag', 'p' );
				}
				if ( $this->stack->currentNode()->isA( BalanceSets::$headingSet ) ) {
					$this->stack->pop();
				}
				$this->stack->insertHTMLElement( $value, $attribs );
				return true;

			case 'pre':
			case 'listing':
				if ( $this->stack->inButtonScope( 'p' ) ) {
					$this->inBodyMode( 'endtag', 'p' );
				}
				$this->stack->insertHTMLElement( $value, $attribs );
				# As described in "simplifications" above:
				# 1. We don't touch the next token, even if it's a linefeed.
				# 2. OMITTED: frameset_ok
				return true;

			# OMITTED: <form>

			case 'li':
				# OMITTED: frameset_ok
				foreach ( $this->stack as $node ) {
					if ( $node->isA( 'li' ) ) {
						$this->inBodyMode( 'endtag', 'li' );
						break;
					}
					if (
						$node->isA( BalanceSets::$specialSet ) &&
						!$node->isA( BalanceSets::$addressDivPSet )
					) {
						break;
					}
				}
				if ( $this->stack->inButtonScope( 'p' ) ) {
					$this->inBodyMode( 'endtag', 'p' );
				}
				$this->stack->insertHTMLElement( $value, $attribs );
				return true;

			case 'dd':
			case 'dt':
				# OMITTED: frameset_ok
				foreach ( $this->stack as $node ) {
					if ( $node->isA( 'dd' ) ) {
						$this->inBodyMode( 'endtag', 'dd' );
						break;
					}
					if ( $node->isA( 'dt' ) ) {
						$this->inBodyMode( 'endtag', 'dt' );
						break;
					}
					if (
						$node->isA( BalanceSets::$specialSet ) &&
						!$node->isA( BalanceSets::$addressDivPSet )
					) {
						break;
					}
				}
				if ( $this->stack->inButtonScope( 'p' ) ) {
					$this->inBodyMode( 'endtag', 'p' );
				}
				$this->stack->insertHTMLElement( $value, $attribs );
				return true;

			# OMITTED: <plaintext>

			case 'button':
				if ( $this->stack->inScope( 'button' ) ) {
					$this->inBodyMode( 'endtag', 'button' );
					return $this->insertToken( $token, $value, $attribs, $selfclose );
				}
				$this->afe->reconstruct( $this->stack );
				$this->stack->insertHTMLElement( $value, $attribs );
				return true;

			case 'a':
				$activeElement = $this->afe->findElementByTag( 'a' );
				if ( $activeElement ) {
					$this->inBodyMode( 'endtag', 'a' );
					if ( $this->afe->indexOf( $activeElement ) >= 0 ) {
						$this->afe->remove( $activeElement );
						// Don't flatten here, since when we fall
						// through below we might foster parent
						// the new <a> tag inside this one.
						$this->stack->removeElement( $activeElement, false );
					}
				}
				/* Falls through */
			case 'b':
			case 'big':
			case 'code':
			case 'em':
			case 'font':
			case 'i':
			case 's':
			case 'small':
			case 'strike':
			case 'strong':
			case 'tt':
			case 'u':
				$this->afe->reconstruct( $this->stack );
				$this->afe->push( $this->stack->insertHTMLElement( $value, $attribs ), $attribs );
				return true;

			case 'nobr':
				$this->afe->reconstruct( $this->stack );
				if ( $this->stack->inScope( 'nobr' ) ) {
					$this->inBodyMode( 'endtag', 'nobr' );
					$this->afe->reconstruct( $this->stack );
				}
				$this->afe->push( $this->stack->insertHTMLElement( $value, $attribs ), $attribs );
				return true;

			case 'applet':
			case 'marquee':
			case 'object':
				$this->afe->reconstruct( $this->stack );
				$this->stack->insertHTMLElement( $value, $attribs );
				$this->afe->insertMarker();
				# OMITTED: frameset_ok
				return true;

			case 'table':
				# The document is never in "quirks mode"; see simplifications
				# above.
				if ( $this->stack->inButtonScope( 'p' ) ) {
					$this->inBodyMode( 'endtag', 'p' );
				}
				$this->stack->insertHTMLElement( $value, $attribs );
				# OMITTED: frameset_ok
				$this->switchMode( 'inTableMode' );
				return true;

			case 'area':
			case 'br':
			case 'embed':
			case 'img':
			case 'keygen':
			case 'wbr':
				$this->afe->reconstruct( $this->stack );
				$this->stack->insertHTMLElement( $value, $attribs );
				$this->stack->pop();
				# OMITTED: frameset_ok
				return true;

			case 'input':
				$this->afe->reconstruct( $this->stack );
				$this->stack->insertHTMLElement( $value, $attribs );
				$this->stack->pop();
				# OMITTED: frameset_ok
				# (hence we don't need to examine the tag's "type" attribute)
				return true;

			case 'menuitem':
			case 'param':
			case 'source':
			case 'track':
				$this->stack->insertHTMLElement( $value, $attribs );
				$this->stack->pop();
				return true;

			case 'hr':
				if ( $this->stack->inButtonScope( 'p' ) ) {
					$this->inBodyMode( 'endtag', 'p' );
				}
				$this->stack->insertHTMLElement( $value, $attribs );
				$this->stack->pop();
				return true;

			case 'image':
				# warts!
				return $this->inBodyMode( $token, 'img', $attribs, $selfclose );

			# OMITTED: <isindex>
			# OMITTED: <textarea>
			# OMITTED: <xmp>
			# OMITTED: <iframe>
			# OMITTED: <noembed>
			# OMITTED: <noscript>

			# OMITTED: <select>
			/*
			case 'select':
				$this->afe->reconstruct( $this->stack );
				$this->stack->insertHTMLElement( $value, $attribs );
				switch ( $this->parseMode ) {
				case 'inTableMode':
				case 'inCaptionMode':
				case 'inTableBodyMode':
				case 'inRowMode':
				case 'inCellMode':
					$this->switchMode( 'inSelectInTableMode' );
					return true;
				default:
					$this->switchMode( 'inSelectMode' );
					return true;
				}
			*/

			case 'optgroup':
			case 'option':
				if ( $this->stack->currentNode()->isA( 'option' ) ) {
					$this->inBodyMode( 'endtag', 'option' );
				}
				$this->afe->reconstruct( $this->stack );
				$this->stack->insertHTMLElement( $value, $attribs );
				return true;

			case 'rb':
			case 'rtc':
				if ( $this->stack->inScope( 'ruby' ) ) {
					$this->stack->generateImpliedEndTags();
				}
				$this->stack->insertHTMLElement( $value, $attribs );
				return true;

			case 'rp':
			case 'rt':
				if ( $this->stack->inScope( 'ruby' ) ) {
					$this->stack->generateImpliedEndTags( 'rtc' );
				}
				$this->stack->insertHTMLElement( $value, $attribs );
				return true;

			case 'math':
				$this->afe->reconstruct( $this->stack );
				# We skip the spec's "adjust MathML attributes" and
				# "adjust foreign attributes" steps, since the browser will
				# do this later when it parses the output and it doesn't affect
				# balancing.
				$this->stack->insertForeignElement(
					BalanceSets::MATHML_NAMESPACE, $value, $attribs
				);
				if ( $selfclose ) {
					# emit explicit </math> tag.
					$this->stack->pop();
				}
				return true;

			case 'svg':
				$this->afe->reconstruct( $this->stack );
				# We skip the spec's "adjust SVG attributes" and
				# "adjust foreign attributes" steps, since the browser will
				# do this later when it parses the output and it doesn't affect
				# balancing.
				$this->stack->insertForeignElement(
					BalanceSets::SVG_NAMESPACE, $value, $attribs
				);
				if ( $selfclose ) {
					# emit explicit </svg> tag.
					$this->stack->pop();
				}
				return true;

			case 'caption':
			case 'col':
			case 'colgroup':
			# OMITTED: <frame>
			case 'head':
			case 'tbody':
			case 'td':
			case 'tfoot':
			case 'th':
			case 'thead':
			case 'tr':
				// Ignore table tags if we're not inTableMode
				return true;
			}

			// Handle any other start tag here
			$this->afe->reconstruct( $this->stack );
			$this->stack->insertHTMLElement( $value, $attribs );
			return true;
		} elseif ( $token === 'endtag' ) {
			switch ( $value ) {
			# </body>,</html> are unsupported.

			case 'template':
				return $this->inHeadMode( $token, $value, $attribs, $selfclose );

			case 'address':
			case 'article':
			case 'aside':
			case 'blockquote':
			case 'button':
			case 'center':
			case 'details':
			case 'dialog':
			case 'dir':
			case 'div':
			case 'dl':
			case 'fieldset':
			case 'figcaption':
			case 'figure':
			case 'footer':
			case 'header':
			case 'hgroup':
			case 'listing':
			case 'main':
			case 'menu':
			case 'nav':
			case 'ol':
			case 'pre':
			case 'section':
			case 'summary':
			case 'ul':
				// Ignore if there is not a matching open tag
				if ( !$this->stack->inScope( $value ) ) {
					return true;
				}
				$this->stack->generateImpliedEndTags();
				$this->stack->popTag( $value );
				return true;

			# OMITTED: <form>

			case 'p':
				if ( !$this->stack->inButtonScope( 'p' ) ) {
					$this->inBodyMode( 'tag', 'p', '' );
					return $this->insertToken( $token, $value, $attribs, $selfclose );
				}
				$this->stack->generateImpliedEndTags( $value );
				$this->stack->popTag( $value );
				return true;

			case 'li':
				if ( !$this->stack->inListItemScope( $value ) ) {
					return true; # ignore
				}
				$this->stack->generateImpliedEndTags( $value );
				$this->stack->popTag( $value );
				return true;

			case 'dd':
			case 'dt':
				if ( !$this->stack->inScope( $value ) ) {
					return true; # ignore
				}
				$this->stack->generateImpliedEndTags( $value );
				$this->stack->popTag( $value );
				return true;

			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				if ( !$this->stack->inScope( BalanceSets::$headingSet ) ) {
					return;
				}
				$this->stack->generateImpliedEndTags();
				$this->stack->popTag( BalanceSets::$headingSet );
				return true;

			case 'sarcasm':
				# Take a deep breath, then:
				break;

			case 'a':
			case 'b':
			case 'big':
			case 'code':
			case 'em':
			case 'font':
			case 'i':
			case 'nobr':
			case 's':
			case 'small':
			case 'strike':
			case 'strong':
			case 'tt':
			case 'u':
				if ( $this->stack->adoptionAgency( $value, $this->afe ) ) {
					return true; # If we did something, we're done.
				}
				break; # Go to the "any other end tag" case.

			case 'applet':
			case 'marquee':
			case 'object':
				if ( !$this->stack->inScope( $value ) ) {
					return true; # ignore
				}
				$this->stack->generateImpliedEndTags();
				$this->stack->popTag( $value );
				$this->afe->clearToMarker();
				return true;

			case 'br':
				# Turn </br> into <br>
				return $this->inBodyMode( 'tag', $value, '' );
			}

			// Any other end tag goes here
			foreach ( $this->stack as $i => $node ) {
				if ( $node->isA( $value ) ) {
					$this->stack->generateImpliedEndTags( $value );
					$this->stack->popTo( $i ); # including $i
					break;
				} elseif ( $node->isA( BalanceSets::$specialSet ) ) {
					return true; // ignore this close token.
				}
			}
			return true;
		} else {
			Assert::invariant( false, "Bad token type: $token" );
		}
	}

	private function inTableMode( $token, $value, $attribs = null, $selfclose = false ) {
		if ( $token === 'text' ) {
			if ( $this->textIntegrationMode ) {
				return $this->inBodyMode( $token, $value, $attribs, $selfclose );
			} elseif ( $this->stack->currentNode()->isA( BalanceSets::$tableSectionRowSet ) ) {
				$this->pendingTableText = '';
				$this->originalInsertionMode = $this->parseMode;
				return $this->switchModeAndReprocess( 'inTableTextMode', $token, $value, $attribs, $selfclose );
			}
			// fall through to default case.
		} elseif ( $token === 'eof' ) {
			$this->stopParsing();
			return true;
		} elseif ( $token === 'tag' ) {
			switch ( $value ) {
			case 'caption':
				$this->afe->insertMarker();
				$this->stack->insertHTMLElement( $value, $attribs );
				$this->switchMode( 'inCaptionMode' );
				return true;
			case 'colgroup':
				$this->stack->clearToContext( BalanceSets::$tableContextSet );
				$this->stack->insertHTMLElement( $value, $attribs );
				$this->switchMode( 'inColumnGroupMode' );
				return true;
			case 'col':
				$this->inTableMode( 'tag', 'colgroup', '' );
				return $this->insertToken( $token, $value, $attribs, $selfclose );
			case 'tbody':
			case 'tfoot':
			case 'thead':
				$this->stack->clearToContext( BalanceSets::$tableContextSet );
				$this->stack->insertHTMLElement( $value, $attribs );
				$this->switchMode( 'inTableBodyMode' );
				return true;
			case 'td':
			case 'th':
			case 'tr':
				$this->inTableMode( 'tag', 'tbody', '' );
				return $this->insertToken( $token, $value, $attribs, $selfclose );
			case 'table':
				if ( !$this->stack->inTableScope( $value ) ) {
					return true; // Ignore this tag.
				}
				$this->inTableMode( 'endtag', $value );
				return $this->insertToken( $token, $value, $attribs, $selfclose );

			case 'style':
			# OMITTED: <script>
			case 'template':
				return $this->inHeadMode( $token, $value, $attribs, $selfclose );

			case 'input':
				// We rely on Sanitizer::fixTagAttributes having run on $attribs
				// to normalize the form of the tag parameters.
				if ( !preg_match( '/(^| )type="hidden"/i', $attribs ) ) {
					break; // Handle this as "everything else"
				}
				$this->stack->insertHTMLElement( $value, $attribs );
				$this->stack->pop();
				return true;

			# OMITTED: <form>
			}
			// Fall through for "anything else" clause.
		} elseif ( $token === 'endtag' ) {
			switch ( $value ) {
			case 'table':
				if ( !$this->stack->inTableScope( $value ) ) {
					return true; // Ignore.
				}
				$this->stack->popTag( $value );
				$this->resetInsertionMode();
				return true;
			# OMITTED: <body>
			case 'caption':
			case 'col':
			case 'colgroup':
			# OMITTED: <html>
			case 'tbody':
			case 'td':
			case 'tfoot':
			case 'th':
			case 'thead':
			case 'tr':
				return true; // Ignore the token.
			case 'template':
				return $this->inHeadMode( $token, $value, $attribs, $selfclose );
			}
			// Fall through for "anything else" clause.
		}
		// This is the "anything else" case:
		$this->stack->fosterParentMode = true;
		$this->inBodyMode( $token, $value, $attribs, $selfclose );
		$this->stack->fosterParentMode = false;
		return true;
	}

	private function inTableTextMode( $token, $value, $attribs = null, $selfclose = false ) {
		if ( $token === 'text' ) {
			$this->pendingTableText .= $value;
			return true;
		}
		// Non-text token:
		$text = $this->pendingTableText;
		$this->pendingTableText = '';
		if ( preg_match( '/[^\x09\x0A\x0C\x0D\x20]/', $text ) ) {
			// This should match the "anything else" case inTableMode
			$this->stack->fosterParentMode = true;
			$this->inBodyMode( 'text', $text );
			$this->stack->fosterParentMode = false;
		} else {
			// Pending text is just whitespace.
			$this->stack->insertText( $text );
		}
		return $this->switchModeAndReprocess(
			$this->originalInsertionMode, $token, $value, $attribs, $selfclose
		);
	}

	// helper for inCaptionMode
	private function endCaption() {
		if ( !$this->stack->inTableScope( 'caption' ) ) {
			return false;
		}
		$this->stack->generateImpliedEndTags();
		$this->stack->popTag( 'caption' );
		$this->afe->clearToMarker();
		$this->switchMode( 'inTableMode' );
		return true;
	}

	private function inCaptionMode( $token, $value, $attribs = null, $selfclose = false ) {
		if ( $token === 'tag' ) {
			switch ( $value ) {
			case 'caption':
			case 'col':
			case 'colgroup':
			case 'tbody':
			case 'td':
			case 'tfoot':
			case 'th':
			case 'thead':
			case 'tr':
				if ( $this->endCaption() ) {
					$this->insertToken( $token, $value, $attribs, $selfclose );
				}
				return true;
			}
			// Fall through to "anything else" case.
		} elseif ( $token === 'endtag' ) {
			switch ( $value ) {
			case 'caption':
				$this->endCaption();
				return true;
			case 'table':
				if ( $this->endCaption() ) {
					$this->insertToken( $token, $value, $attribs, $selfclose );
				}
				return true;
			case 'body':
			case 'col':
			case 'colgroup':
			# OMITTED: <html>
			case 'tbody':
			case 'td':
			case 'tfoot':
			case 'th':
			case 'thead':
			case 'tr':
				// Ignore the token
				return true;
			}
			// Fall through to "anything else" case.
		}
		// The Anything Else case
		return $this->inBodyMode( $token, $value, $attribs, $selfclose );
	}

	private function inColumnGroupMode( $token, $value, $attribs = null, $selfclose = false ) {
		if ( $token === 'text' ) {
			if ( preg_match( '/^[\x09\x0A\x0C\x0D\x20]+/', $value, $matches ) ) {
				$this->stack->insertText( $matches[0] );
				$value = substr( $value, strlen( $matches[0] ) );
			}
			if ( strlen( $value ) === 0 ) {
				return true; // All text handled.
			}
			// Fall through to handle non-whitespace below.
		} elseif ( $token === 'tag' ) {
			switch ( $value ) {
			# OMITTED: <html>
			case 'col':
				$this->stack->insertHTMLElement( $value, $attribs );
				$this->stack->pop();
				return true;
			case 'template':
				return $this->inHeadMode( $token, $value, $attribs, $selfclose );
			}
			// Fall through for "anything else".
		} elseif ( $token === 'endtag' ) {
			switch ( $value ) {
			case 'colgroup':
				if ( !$this->stack->currentNode()->isA( 'colgroup' ) ) {
					return true; // Ignore the token.
				}
				$this->stack->pop();
				$this->switchMode( 'inTableMode' );
				return true;
			case 'col':
				return true; // Ignore the token.
			case 'template':
				return $this->inHeadMode( $token, $value, $attribs, $selfclose );
			}
			// Fall through for "anything else".
		} elseif ( $token === 'eof' ) {
			return $this->inBodyMode( $token, $value, $attribs, $selfclose );
		}

		// Anything else
		if ( !$this->stack->currentNode()->isA( 'colgroup' ) ) {
			return true; // Ignore the token.
		}
		$this->inColumnGroupMode( 'endtag', 'colgroup' );
		return $this->insertToken( $token, $value, $attribs, $selfclose );
	}

	// Helper function for inTableBodyMode
	private function endSection() {
		if ( !(
			$this->stack->inTableScope( 'tbody' ) ||
			$this->stack->inTableScope( 'thead' ) ||
			$this->stack->inTableScope( 'tfoot' )
		) ) {
			return false;
		}
		$this->stack->clearToContext( BalanceSets::$tableBodyContextSet );
		$this->stack->pop();
		$this->switchMode( 'inTableMode' );
		return true;
	}
	private function inTableBodyMode( $token, $value, $attribs = null, $selfclose = false ) {
		if ( $token === 'tag' ) {
			switch ( $value ) {
			case 'tr':
				$this->stack->clearToContext( BalanceSets::$tableBodyContextSet );
				$this->stack->insertHTMLElement( $value, $attribs );
				$this->switchMode( 'inRowMode' );
				return true;
			case 'th':
			case 'td':
				$this->inTableBodyMode( 'tag', 'tr', '' );
				$this->insertToken( $token, $value, $attribs, $selfclose );
				return true;
			case 'caption':
			case 'col':
			case 'colgroup':
			case 'tbody':
			case 'tfoot':
			case 'thead':
				if ( $this->endSection() ) {
					$this->insertToken( $token, $value, $attribs, $selfclose );
				}
				return true;
			}
		} elseif ( $token === 'endtag' ) {
			switch ( $value ) {
			case 'table':
				if ( $this->endSection() ) {
					$this->insertToken( $token, $value, $attribs, $selfclose );
				}
				return true;
			case 'tbody':
			case 'tfoot':
			case 'thead':
				if ( $this->stack->inTableScope( $value ) ) {
					$this->endSection();
				}
				return true;
			# OMITTED: <body>
			case 'caption':
			case 'col':
			case 'colgroup':
			# OMITTED: <html>
			case 'td':
			case 'th':
			case 'tr':
				return true; // Ignore the token.
			}
		}
		// Anything else:
		return $this->inTableMode( $token, $value, $attribs, $selfclose );
	}

	// Helper function for inRowMode
	private function endRow() {
		if ( !$this->stack->inTableScope( 'tr' ) ) {
			return false;
		}
		$this->stack->clearToContext( BalanceSets::$tableRowContextSet );
		$this->stack->pop();
		$this->switchMode( 'inTableBodyMode' );
		return true;
	}
	private function inRowMode( $token, $value, $attribs = null, $selfclose = false ) {
		if ( $token === 'tag' ) {
			switch ( $value ) {
			case 'th':
			case 'td':
				$this->stack->clearToContext( BalanceSets::$tableRowContextSet );
				$this->stack->insertHTMLElement( $value, $attribs );
				$this->switchMode( 'inCellMode' );
				$this->afe->insertMarker();
				return true;
			case 'caption':
			case 'col':
			case 'colgroup':
			case 'tbody':
			case 'tfoot':
			case 'thead':
			case 'tr':
				if ( $this->endRow() ) {
					$this->insertToken( $token, $value, $attribs, $selfclose );
				}
				return true;
			}
		} elseif ( $token === 'endtag' ) {
			switch ( $value ) {
			case 'tr':
				$this->endRow();
				return true;
			case 'table':
				if ( $this->endRow() ) {
					$this->insertToken( $token, $value, $attribs, $selfclose );
				}
				return true;
			case 'tbody':
			case 'tfoot':
			case 'thead':
				if (
					$this->stack->inTableScope( $value ) &&
					$this->endRow()
				) {
					$this->insertToken( $token, $value, $attribs, $selfclose );
				}
				return true;
			# OMITTED: <body>
			case 'caption':
			case 'col':
			case 'colgroup':
			# OMITTED: <html>
			case 'td':
			case 'th':
				return true; // Ignore the token.
			}
		}
		// Anything else:
		return $this->inTableMode( $token, $value, $attribs, $selfclose );
	}

	// Helper for inCellMode
	private function endCell() {
		if ( $this->stack->inTableScope( 'td' ) ) {
			$this->inCellMode( 'endtag', 'td' );
			return true;
		} elseif ( $this->stack->inTableScope( 'th' ) ) {
			$this->inCellMode( 'endtag', 'th' );
			return true;
		} else {
			return false;
		}
	}
	private function inCellMode( $token, $value, $attribs = null, $selfclose = false ) {
		if ( $token === 'tag' ) {
			switch ( $value ) {
			case 'caption':
			case 'col':
			case 'colgroup':
			case 'tbody':
			case 'td':
			case 'tfoot':
			case 'th':
			case 'thead':
			case 'tr':
				if ( $this->endCell() ) {
					$this->insertToken( $token, $value, $attribs, $selfclose );
				}
				return true;
			}
		} elseif ( $token === 'endtag' ) {
			switch ( $value ) {
			case 'td':
			case 'th':
				if ( $this->stack->inTableScope( $value ) ) {
					$this->stack->generateImpliedEndTags();
					$this->stack->popTag( $value );
					$this->afe->clearToMarker();
					$this->switchMode( 'inRowMode' );
				}
				return true;
			# OMITTED: <body>
			case 'caption':
			case 'col':
			case 'colgroup':
			# OMITTED: <html>
				return true;

			case 'table':
			case 'tbody':
			case 'tfoot':
			case 'thead':
			case 'tr':
				if ( $this->stack->inTableScope( $value ) ) {
					$this->stack->generateImpliedEndTags();
					$this->stack->popTag( BalanceSets::$tableCellSet );
					$this->afe->clearToMarker();
					$this->switchMode( 'inRowMode' );
					$this->insertToken( $token, $value, $attribs, $selfclose );
				}
				return true;
			}
		}
		// Anything else:
		return $this->inBodyMode( $token, $value, $attribs, $selfclose );
	}

	# OMITTED: <select>
	/*
	private function inSelectMode( $token, $value, $attribs = null, $selfclose = false ) {
		Assert::invariant( false, 'Unimplemented' );
	}

	private function inSelectInTableMode( $token, $value, $attribs = null, $selfclose = false ) {
		Assert::invariant( false, 'Unimplemented' );
	}
	*/

	private function inTemplateMode( $token, $value, $attribs = null, $selfclose = false ) {
		if ( $token === 'text' ) {
			return $this->inBodyMode( $token, $value, $attribs, $selfclose );
		} elseif ( $token === 'eof' ) {
			if ( $this->stack->indexOf( 'template' ) < 0 ) {
				$this->stopParsing();
			} else {
				$this->stack->popTag( 'template' );
				$this->afe->clearToMarker();
				array_pop( $this->templateInsertionModes );
				$this->resetInsertionMode();
				$this->insertToken( $token, $value, $attribs, $selfclose );
			}
			return true;
		} elseif ( $token === 'tag' ) {
			switch ( $value ) {
			case 'base':
			case 'basefont':
			case 'bgsound':
			case 'link':
			case 'meta':
			case 'noframes':
			# OMITTED: <script>
			case 'style':
			case 'template':
			# OMITTED: <title>
				return $this->inHeadMode( $token, $value, $attribs, $selfclose );

			case 'caption':
			case 'colgroup':
			case 'tbody':
			case 'tfoot':
			case 'thead':
				return $this->switchModeAndReprocess(
					'inTableMode', $token, $value, $attribs, $selfclose
				);

			case 'col':
				return $this->switchModeAndReprocess(
					'inColumnGroupMode', $token, $value, $attribs, $selfclose
				);

			case 'tr':
				return $this->switchModeAndReprocess(
					'inTableBodyMode', $token, $value, $attribs, $selfclose
				);

			case 'td':
			case 'th':
				return $this->switchModeAndReprocess(
					'inRowMode', $token, $value, $attribs, $selfclose
				);
			}
			return $this->switchModeAndReprocess(
				'inBodyMode', $token, $value, $attribs, $selfclose
			);
		} elseif ( $token === 'endtag' ) {
			switch ( $value ) {
			case 'template':
				return $this->inHeadMode( $token, $value, $attribs, $selfclose );
			}
			return true;
		} else {
			Assert::invariant( false, "Bad token type: $token" );
		}
	}
}
