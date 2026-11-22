<?php
/**
 * Engine dispatch after the Phase 7A performance pass: attribute listeners
 * (Fix_Base::WITH_ATTRIBUTE), the plain-opener path, and Token::attr()
 * answering from the attribute names the engine already read. Every case
 * compares against what the full path and the processor would give.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Plan;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;

require_once __DIR__ . '/helpers/class-engine-test-case.php';

/**
 * Records what reaches it: openers as "TAG#ordinal", closers as "/TAG#ordinal",
 * and for each opener the value attr() gives for a chosen attribute name.
 */
class Softglaze_A11y_Test_Dispatch_Probe extends Softglaze_A11y_Test_Fix {

	/**
	 * Tokens seen.
	 *
	 * @var string[]
	 */
	public $seen = array();

	/**
	 * Attribute name => values attr() returned, per opener.
	 *
	 * @var array
	 */
	public $values = array();

	/**
	 * Whether each opener sat inside aria-hidden content.
	 *
	 * @var bool[]
	 */
	public $hidden = array();

	/**
	 * Tags.
	 *
	 * @var string[]
	 */
	private $tag_list;

	/**
	 * Attribute names read on each opener.
	 *
	 * @var string[]
	 */
	private $read;

	/**
	 * Creates the probe.
	 *
	 * @param string[] $tag_list Tags.
	 * @param string[] $read     Attribute names to read on each opener.
	 */
	public function __construct( array $tag_list, array $read = array() ) {
		$this->tag_list = $tag_list;
		$this->read     = $read;
	}

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'test-dispatch-probe';
	}

	/**
	 * Tags.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return $this->tag_list;
	}

	/**
	 * Clears the lists.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->seen   = array();
		$this->values = array();
		$this->hidden = array();
	}

	/**
	 * Notes the token.
	 *
	 * @param Token          $token    Token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
		$this->seen[] = ( $token->is_closer ? '/' : '' ) . $token->name . '#' . $token->ordinal;
		if ( $token->is_closer ) {
			return;
		}
		$this->hidden[] = $token->inside_aria_hidden();
		foreach ( $this->read as $attribute ) {
			$this->values[ $attribute ][] = $token->attr( $attribute );
		}
	}
}

/**
 * Engine dispatch.
 */
class Test_Engine_Dispatch extends Softglaze_A11y_Engine_TestCase {

	/**
	 * Runs a probe over a fragment.
	 *
	 * @param Softglaze_A11y_Test_Dispatch_Probe $probe Probe.
	 * @param string                             $html  Fragment.
	 * @param array                              $extra Other fixes (Live).
	 * @return void
	 */
	private static function run_probe( $probe, $html, array $extra = array() ) {
		$fixes  = array_merge( array( $probe ), $extra );
		$states = array();
		foreach ( $fixes as $fix ) {
			$states[ $fix->id() ] = 'live';
		}
		( new Engine( $fixes, $states, array( 'max_ms' => 10000 ) ) )->run( $html, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT );
	}

	/**
	 * An attribute entry shows the fix every opener that carries the
	 * attribute, whatever its tag and case, once, and never a closer.
	 */
	public function test_attribute_listeners() {
		$html  = '<p tabindex="1">a</p><div><a href="#" tabindex="0">b</a></div><span TABINDEX>c</span><i></i><button tabindex="-1"></button>';
		$probe = new Softglaze_A11y_Test_Dispatch_Probe( array( Fix_Base::WITH_ATTRIBUTE . 'tabindex' ), array( 'tabindex' ) );
		self::run_probe( $probe, $html );
		$this->assertSame( array( 'P#0', 'A#2', 'SPAN#3', 'BUTTON#5' ), $probe->seen );
		$this->assertSame( array( '1', '0', true, '-1' ), $probe->values['tabindex'], 'Values as the processor gives them (a boolean attribute is true).' );

		$probe = new Softglaze_A11y_Test_Dispatch_Probe( array( 'A', Fix_Base::WITH_ATTRIBUTE . 'tabindex' ) );
		self::run_probe( $probe, $html );
		$this->assertSame( array( 'P#0', 'A#2', '/A#2', 'SPAN#3', 'BUTTON#5' ), $probe->seen, 'A tag the fix names and carries the attribute is shown once; its closer comes through the tag.' );

		$probe = new Softglaze_A11y_Test_Dispatch_Probe( array( Fix_Base::WITH_ATTRIBUTE . 'tabindex', Fix_Base::WITH_ATTRIBUTE . 'role' ) );
		self::run_probe( $probe, '<div role="button" tabindex="0">x</div><div role="none"></div><div></div>' );
		$this->assertSame( array( 'DIV#0', 'DIV#1' ), $probe->seen, 'Two listened attributes on one opener: shown once.' );

		$probe = new Softglaze_A11y_Test_Dispatch_Probe( array( Fix_Base::IN_HIDDEN, Fix_Base::WITH_ATTRIBUTE . 'tabindex' ) );
		self::run_probe( $probe, '<div aria-hidden="true"><a href="/x" tabindex="0">x</a><span>y</span></div><b tabindex="2"></b>' );
		$this->assertSame( array( 'DIV#0', 'A#1', 'SPAN#2', 'B#3' ), $probe->seen, 'Hidden content and an attribute together: each opener once.' );
	}

	/**
	 * F14 now listens to tabindex only and still resets every positive value.
	 */
	public function test_positive_tabindex_by_attribute() {
		$fix    = new \SoftGlaze\AccessibilityRepair\Fixes\Fix_Positive_Tabindex();
		$engine = new Engine( array( $fix ), array( 'positive-tabindex' => 'live' ) );
		$html   = '<div tabindex="3">a</div><svg tabindex="2"><path tabindex="1"/></svg><input tabindex=" +4"><p tabindex="0">b</p><span tabindex="-1">c</span>';
		$this->assertSame(
			'<div tabindex="0">a</div><svg tabindex="0"><path tabindex="0"/></svg><input tabindex="0"><p tabindex="0">b</p><span tabindex="-1">c</span>',
			$engine->run( $html, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT )
		);
	}

	/**
	 * Plain openers (no tracked tag, no interesting attribute, no listener)
	 * take the short path; nested same-name elements inside a frame still
	 * keep their end tags from closing it early.
	 */
	public function test_plain_openers_keep_frames_intact() {
		$probe = new Softglaze_A11y_Test_Dispatch_Probe( array( 'A' ) );
		self::run_probe( $probe, '<div aria-hidden="true"><div><div>x</div></div><a href="/in">in</a></div><a href="/out">out</a>' );
		$this->assertSame( array( true, false ), $probe->hidden, 'The aria-hidden div closes at its own end tag, not at a nested one.' );

		$probe = new Softglaze_A11y_Test_Dispatch_Probe( array( 'A' ) );
		self::run_probe( $probe, '<svg aria-hidden="true"><g/><g><path/></g><a href="/svg">s</a></svg><a href="/after">a</a>' );
		$this->assertSame( array( true, false ), $probe->hidden, 'Self-closing elements inside SVG never count as open.' );

		$probe = new Softglaze_A11y_Test_Dispatch_Probe( array( 'A' ) );
		self::run_probe( $probe, '<p aria-hidden="true"><br><img src="x.png" alt=""><span><a href="/in">in</a></span></p><a href="/out">out</a>' );
		$this->assertSame( array( true, false ), $probe->hidden );
	}

	/**
	 * Token::attr() answers from the names the engine read; the result equals
	 * the processor's, also for upper-case names and boolean attributes.
	 */
	public function test_token_attr_matches_the_processor() {
		$html  = '<a href="/x" HIDDEN data-x="1" aria-label="  Go &amp; see ">x</a><a>y</a>';
		$read  = array( 'href', 'HREF', 'hidden', 'data-x', 'aria-label', 'title', 'Aria-Label' );
		$probe = new Softglaze_A11y_Test_Dispatch_Probe( array( 'A' ), $read );
		self::run_probe( $probe, $html );

		$processor = new WP_HTML_Tag_Processor( $html );
		$expected  = array();
		while ( $processor->next_tag( 'A' ) ) {
			foreach ( $read as $attribute ) {
				$expected[ $attribute ][] = $processor->get_attribute( $attribute );
			}
		}
		$this->assertSame( $expected, $probe->values );
		$this->assertSame( '  Go & see ', $probe->values['aria-label'][0] );
		$this->assertNull( $probe->values['title'][0] );
	}

	/**
	 * The bundled fixes and checks give the same result whether the engine
	 * runs them all together or one at a time (dispatch caches are per run).
	 */
	public function test_dispatch_caches_are_per_run() {
		$html   = '<div tabindex="2" role="button">a</div><p tabindex="5">b</p>';
		$engine = self::engine( 'live' );
		$first  = $engine->run( $html, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT );
		$engine->run( '<span>no tabindex</span>', Engine::MODE_VISITOR, Engine::SCOPE_CONTENT );
		$this->assertSame( $first, $engine->run( $html, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT ) );
		$this->assertSame( '<div tabindex="0" role="button">a</div><p tabindex="0">b</p>', $first );
	}
}
