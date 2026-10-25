<?php
/**
 * The repair engine: analyse, plan, apply, check, or return the input.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair;

use Throwable;
use WP_HTML_Tag_Processor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two passes over one string (blueprint section 3.2, decision D5). The analyse
 * pass walks every token with next_token(), numbers tag openers, keeps a small
 * stack of the elements that matter and lets each fix plan attribute edits.
 * The apply pass walks the same string with the same iterator and the same
 * opener predicate and calls set_attribute() at the planned ordinals. Any
 * guard, mismatch or throwable returns the input string unchanged.
 *
 * When an active fix or check uses names (Fix_Base::uses_names()), the
 * analyse pass also builds one Accessible_Name per link, button, field and
 * role="button" element and resolves them after the last token, so every
 * fix reads the same naming facts instead of working them out again.
 *
 * The engine has no side effects: it registers no hooks, writes nothing and
 * prints nothing. What happened is in recorder() after each run.
 */
class Engine {

	const MODE_VISITOR        = 'visitor';
	const MODE_ADMIN_REPAIRED = 'admin_repaired';
	const MODE_ADMIN_ORIGINAL = 'admin_original';
	const MODE_SCAN           = 'scan';

	const SCOPE_PAGE    = 'page';
	const SCOPE_CONTENT = 'content';

	const STATES = array( 'off', 'preview', 'live' );

	/**
	 * Per mode: fix states that are analysed, and fix states whose edits are applied.
	 */
	const MODE_STATES = array(
		'visitor'        => array(
			'analyse' => array( 'live' ),
			'apply'   => array( 'live' ),
		),
		'admin_repaired' => array(
			'analyse' => array( 'live', 'preview', 'off' ),
			'apply'   => array( 'live', 'preview' ),
		),
		'admin_original' => array(
			'analyse' => array(),
			'apply'   => array(),
		),
		'scan'           => array(
			'analyse' => array( 'live', 'preview', 'off' ),
			'apply'   => array(),
		),
	);

	const DEFAULT_MAX_BYTES = 2097152;
	const DEFAULT_MAX_MS    = 100;

	/**
	 * The clock is read once per this many tokens.
	 */
	const CLOCK_EVERY = 256;

	/**
	 * The apply pass hands its pending updates to the processor after this many edits.
	 */
	const FLUSH_EVERY = 16;

	/**
	 * Modes in which the report-only checks run.
	 */
	const MODE_CHECKS = array(
		'admin_repaired' => true,
		'scan'           => true,
	);

	/**
	 * Elements kept on the stack; true when the element collects text for its name.
	 */
	const TRACKED = array(
		'A'          => true,
		'BUTTON'     => true,
		'LABEL'      => true,
		'H1'         => true,
		'H2'         => true,
		'H3'         => true,
		'H4'         => true,
		'H5'         => true,
		'H6'         => true,
		'FIGCAPTION' => true,
		'FORM'       => false,
		'ARTICLE'    => false,
		'NAV'        => false,
		'MAIN'       => false,
		'SVG'        => false,
		'FIGURE'     => false,
		'TABLE'      => false,
	);

	/**
	 * Elements kept on the stack, collecting text, only inside an open link or
	 * button: where icon fonts sit, so fixes can see what an icon contains.
	 */
	const TRACKED_IN_CONTROLS = array(
		'I'    => true,
		'SPAN' => true,
	);

	/**
	 * Elements that can carry naming facts without a role (Accessible_Name::kind_for()).
	 */
	const NAMEABLE = array(
		'A'        => true,
		'BUTTON'   => true,
		'INPUT'    => true,
		'SELECT'   => true,
		'TEXTAREA' => true,
	);

	/**
	 * Other elements open_tag() treats specially by their tag: SVG titles
	 * (text and the has_svg_title flag) and search elements (search forms).
	 */
	const PLAIN_NEVER = array(
		'TITLE'  => true,
		'SEARCH' => true,
	);

	/**
	 * Elements inside a link or button that the naming facts count (images,
	 * SVG) or take icon class names from.
	 */
	const CONTROL_PARTS = array(
		'IMG'  => true,
		'SVG'  => true,
		'I'    => true,
		'SPAN' => true,
	);

	/**
	 * At most this many class names of icon elements are kept per control.
	 */
	const ICON_CLASS_LIMIT = 24;

	const HEADINGS = array(
		'H1' => true,
		'H2' => true,
		'H3' => true,
		'H4' => true,
		'H5' => true,
		'H6' => true,
	);

	/**
	 * Elements without an end tag.
	 */
	const VOID = array(
		'AREA'     => true,
		'BASE'     => true,
		'BASEFONT' => true,
		'BGSOUND'  => true,
		'BR'       => true,
		'COL'      => true,
		'EMBED'    => true,
		'FRAME'    => true,
		'HR'       => true,
		'IMG'      => true,
		'INPUT'    => true,
		'KEYGEN'   => true,
		'LINK'     => true,
		'META'     => true,
		'PARAM'    => true,
		'SOURCE'   => true,
		'TRACK'    => true,
		'WBR'      => true,
	);

	/**
	 * Elements whose contents and end tag belong to the opening token.
	 */
	const RAW = array(
		'IFRAME'   => true,
		'NOEMBED'  => true,
		'NOFRAMES' => true,
		'SCRIPT'   => true,
		'STYLE'    => true,
		'TEXTAREA' => true,
		'TITLE'    => true,
		'XMP'      => true,
	);

	/**
	 * Start tags that end an open p element (the HTML parser's "close a p element").
	 */
	const P_ENDED_BY_START = array(
		'ADDRESS'    => true,
		'ARTICLE'    => true,
		'ASIDE'      => true,
		'BLOCKQUOTE' => true,
		'CENTER'     => true,
		'DD'         => true,
		'DETAILS'    => true,
		'DIALOG'     => true,
		'DIR'        => true,
		'DIV'        => true,
		'DL'         => true,
		'DT'         => true,
		'FIELDSET'   => true,
		'FIGCAPTION' => true,
		'FIGURE'     => true,
		'FOOTER'     => true,
		'FORM'       => true,
		'H1'         => true,
		'H2'         => true,
		'H3'         => true,
		'H4'         => true,
		'H5'         => true,
		'H6'         => true,
		'HEADER'     => true,
		'HGROUP'     => true,
		'HR'         => true,
		'LI'         => true,
		'LISTING'    => true,
		'MAIN'       => true,
		'MENU'       => true,
		'NAV'        => true,
		'OL'         => true,
		'P'          => true,
		'PLAINTEXT'  => true,
		'PRE'        => true,
		'SEARCH'     => true,
		'SECTION'    => true,
		'SUMMARY'    => true,
		'TABLE'      => true,
		'UL'         => true,
		'XMP'        => true,
	);

	/**
	 * End tags of elements that can hold a p element: they end it too.
	 */
	const P_ENDED_BY_END = array(
		'ADDRESS'    => true,
		'ARTICLE'    => true,
		'ASIDE'      => true,
		'BLOCKQUOTE' => true,
		'BODY'       => true,
		'CAPTION'    => true,
		'CENTER'     => true,
		'DD'         => true,
		'DETAILS'    => true,
		'DIALOG'     => true,
		'DIR'        => true,
		'DIV'        => true,
		'DL'         => true,
		'DT'         => true,
		'FIELDSET'   => true,
		'FIGCAPTION' => true,
		'FIGURE'     => true,
		'FOOTER'     => true,
		'FORM'       => true,
		'H1'         => true,
		'H2'         => true,
		'H3'         => true,
		'H4'         => true,
		'H5'         => true,
		'H6'         => true,
		'HEADER'     => true,
		'HGROUP'     => true,
		'HTML'       => true,
		'LI'         => true,
		'LISTING'    => true,
		'MAIN'       => true,
		'MENU'       => true,
		'NAV'        => true,
		'OL'         => true,
		'PRE'        => true,
		'SEARCH'     => true,
		'SECTION'    => true,
		'SUMMARY'    => true,
		'TABLE'      => true,
		'TBODY'      => true,
		'TD'         => true,
		'TFOOT'      => true,
		'TH'         => true,
		'THEAD'      => true,
		'TR'         => true,
		'UL'         => true,
	);

	/**
	 * Elements whose end tag HTML lets authors leave out, and what ends them
	 * when one of them is a frame (an aria-hidden list item, a paragraph with
	 * a role): 'start', start tags that end it; 'end', end tags of the
	 * elements around it that end it; 'inner', containers of the same kind
	 * (a nested list or table, a button inside a paragraph). While such a
	 * container is open inside the frame, those start and end tags belong to
	 * the container; the end tag of an 'inner' container opened outside the
	 * frame ends it as well. Without these rules a frame whose end tag was
	 * left out would stay open to the end of the input.
	 */
	const IMPLIED_END = array(
		'LI'       => array(
			'start' => array( 'LI' => true ),
			'end'   => array(),
			'inner' => array(
				'UL'    => true,
				'OL'    => true,
				'MENU'  => true,
				'TABLE' => true,
			),
		),
		'DT'       => array(
			'start' => array(
				'DT' => true,
				'DD' => true,
			),
			'end'   => array(),
			'inner' => array(
				'DL'    => true,
				'TABLE' => true,
			),
		),
		'DD'       => array(
			'start' => array(
				'DT' => true,
				'DD' => true,
			),
			'end'   => array(),
			'inner' => array(
				'DL'    => true,
				'TABLE' => true,
			),
		),
		'OPTION'   => array(
			'start' => array(
				'OPTION'   => true,
				'OPTGROUP' => true,
				'HR'       => true,
			),
			'end'   => array(
				'SELECT'   => true,
				'DATALIST' => true,
				'OPTGROUP' => true,
			),
			'inner' => array(),
		),
		'OPTGROUP' => array(
			'start' => array(
				'OPTGROUP' => true,
				'HR'       => true,
			),
			'end'   => array(
				'SELECT'   => true,
				'DATALIST' => true,
			),
			'inner' => array(),
		),
		'TR'       => array(
			'start' => array(
				'TR'       => true,
				'TBODY'    => true,
				'THEAD'    => true,
				'TFOOT'    => true,
				'CAPTION'  => true,
				'COLGROUP' => true,
				'COL'      => true,
			),
			'end'   => array(
				'TBODY' => true,
				'THEAD' => true,
				'TFOOT' => true,
			),
			'inner' => array( 'TABLE' => true ),
		),
		'TD'       => array(
			'start' => array(
				'TD'       => true,
				'TH'       => true,
				'TR'       => true,
				'TBODY'    => true,
				'THEAD'    => true,
				'TFOOT'    => true,
				'CAPTION'  => true,
				'COLGROUP' => true,
				'COL'      => true,
			),
			'end'   => array(
				'TR'    => true,
				'TBODY' => true,
				'THEAD' => true,
				'TFOOT' => true,
			),
			'inner' => array( 'TABLE' => true ),
		),
		'TH'       => array(
			'start' => array(
				'TD'       => true,
				'TH'       => true,
				'TR'       => true,
				'TBODY'    => true,
				'THEAD'    => true,
				'TFOOT'    => true,
				'CAPTION'  => true,
				'COLGROUP' => true,
				'COL'      => true,
			),
			'end'   => array(
				'TR'    => true,
				'TBODY' => true,
				'THEAD' => true,
				'TFOOT' => true,
			),
			'inner' => array( 'TABLE' => true ),
		),
		'THEAD'    => array(
			'start' => array(
				'TBODY'    => true,
				'THEAD'    => true,
				'TFOOT'    => true,
				'CAPTION'  => true,
				'COLGROUP' => true,
				'COL'      => true,
			),
			'end'   => array(),
			'inner' => array( 'TABLE' => true ),
		),
		'TBODY'    => array(
			'start' => array(
				'TBODY'    => true,
				'THEAD'    => true,
				'TFOOT'    => true,
				'CAPTION'  => true,
				'COLGROUP' => true,
				'COL'      => true,
			),
			'end'   => array(),
			'inner' => array( 'TABLE' => true ),
		),
		'TFOOT'    => array(
			'start' => array(
				'TBODY'    => true,
				'THEAD'    => true,
				'TFOOT'    => true,
				'CAPTION'  => true,
				'COLGROUP' => true,
				'COL'      => true,
			),
			'end'   => array(),
			'inner' => array( 'TABLE' => true ),
		),
		'CAPTION'  => array(
			'start' => array(
				'CAPTION'  => true,
				'COL'      => true,
				'COLGROUP' => true,
				'TBODY'    => true,
				'TD'       => true,
				'TFOOT'    => true,
				'TH'       => true,
				'THEAD'    => true,
				'TR'       => true,
			),
			'end'   => array(),
			'inner' => array( 'TABLE' => true ),
		),
		'P'        => array(
			'start' => self::P_ENDED_BY_START,
			'end'   => self::P_ENDED_BY_END,
			'inner' => array(
				'APPLET'   => true,
				'BUTTON'   => true,
				'MARQUEE'  => true,
				'MATH'     => true,
				'OBJECT'   => true,
				'SVG'      => true,
				'TEMPLATE' => true,
			),
		),
	);

	/**
	 * Fixes by id.
	 *
	 * @var Fix[]
	 */
	private $fixes = array();

	/**
	 * Fix id => 'off' | 'preview' | 'live'.
	 *
	 * @var string[]
	 */
	private $states = array();

	/**
	 * Report-only checks.
	 *
	 * @var Check_Base[]
	 */
	private $checks = array();

	/**
	 * Media Library lookups, shared by every run of this engine.
	 *
	 * @var Attachment_Lookup
	 */
	private $attachments;

	/**
	 * Size guard in bytes, before the filter.
	 *
	 * @var int
	 */
	private $max_bytes;

	/**
	 * Time guard in milliseconds, before the filter.
	 *
	 * @var float
	 */
	private $max_ms;

	/**
	 * Recorder of the last run.
	 *
	 * @var Recorder
	 */
	private $recorder;

	/**
	 * The reused token handed to fixes.
	 *
	 * @var Token
	 */
	private $token;

	/**
	 * Open tracked elements, outermost first (analyse pass only).
	 *
	 * @var Element[]
	 */
	private $stack = array();

	/**
	 * Upper-case tag => number of open frames with that tag.
	 *
	 * @var int[]
	 */
	private $open_tags = array();

	/**
	 * Number of open frames that collect text.
	 *
	 * @var int
	 */
	private $collecting = 0;

	/**
	 * Whether this run computes naming facts (an active fix or check uses them).
	 *
	 * @var bool
	 */
	private $names = false;

	/**
	 * Whether this run records main and navigation landmarks (an active fix or check uses them).
	 *
	 * @var bool
	 */
	private $landmarks = false;

	/**
	 * Whether this run records aria-controls targets (an active fix or check uses them).
	 *
	 * @var bool
	 */
	private $controls = false;

	/**
	 * Naming facts of the open links, buttons and role="button" / role="link"
	 * elements, outermost first.
	 *
	 * @var Accessible_Name[]
	 */
	private $containers = array();

	/**
	 * Number of open frames that carry aria-hidden="true".
	 *
	 * @var int
	 */
	private $hidden_open = 0;

	/**
	 * Number of open frames whose end tag may be left out (IMPLIED_END).
	 *
	 * @var int
	 */
	private $implied_open = 0;

	/**
	 * Tag => fixes listening to it, for the current run.
	 *
	 * @var array
	 */
	private $listeners = array();

	/**
	 * Fixes listening to every tag, for the current run.
	 *
	 * @var Fix[]
	 */
	private $listen_all = array();

	/**
	 * Fixes that see every opener in aria-hidden content (Fix_Base::IN_HIDDEN), for the current run.
	 *
	 * @var Fix[]
	 */
	private $hidden_listeners = array();

	/**
	 * Lower-case attribute name => fixes that see every opener carrying it
	 * (Fix_Base::WITH_ATTRIBUTE entries in tags()), for the current run.
	 *
	 * @var array
	 */
	private $attribute_listeners = array();

	/**
	 * Lower-case attribute name => checks that see every opener carrying it, for the current run.
	 *
	 * @var array
	 */
	private $attribute_checks = array();

	/**
	 * Tag => checks listening to it, for the current run.
	 *
	 * @var array
	 */
	private $check_listeners = array();

	/**
	 * Checks listening to every tag, for the current run.
	 *
	 * @var Check_Base[]
	 */
	private $check_all = array();

	/**
	 * Tag => fixes, tag => checks, and tag => fixes that see it only in
	 * aria-hidden content, in the current run (built on first use per tag).
	 *
	 * @var array
	 */
	private $dispatch = array(
		'fixes'  => array(),
		'checks' => array(),
		'hidden' => array(),
	);

	/**
	 * Lower-case attribute names that make an opener worth a closer look in
	 * the current run (see open_tag()).
	 *
	 * @var bool[]
	 */
	private $interesting = array();

	/**
	 * Upper-case tag => whether openers of that tag are plain in the current
	 * run: not tracked, not named, no listener (built on first use per tag).
	 *
	 * @var bool[]
	 */
	private $plain = array();

	/**
	 * Index of the current run.
	 *
	 * @var Document_Index|null
	 */
	private $index = null;

	/**
	 * Plan of the current run.
	 *
	 * @var Plan|null
	 */
	private $plan = null;

	/**
	 * Creates an engine for a set of fixes and their states.
	 *
	 * @param Fix[]    $fixes   Fix objects; duplicates by id are ignored.
	 * @param string[] $states  Fix id => state; missing or unknown states count as 'off'.
	 * @param array    $options Optional 'max_bytes' (int) and 'max_ms' (number), filterable per run;
	 *                          'checks' (Check_Base[]) for the report; 'attachments' (Attachment_Lookup).
	 */
	public function __construct( array $fixes, array $states = array(), array $options = array() ) {
		foreach ( $fixes as $fix ) {
			if ( ! $fix instanceof Fix ) {
				continue;
			}
			$id = $fix->id();
			if ( '' !== $id && ! isset( $this->fixes[ $id ] ) ) {
				$this->fixes[ $id ] = $fix;
			}
		}
		foreach ( $this->fixes as $id => $fix ) {
			$state               = isset( $states[ $id ] ) ? $states[ $id ] : 'off';
			$this->states[ $id ] = in_array( $state, self::STATES, true ) ? $state : 'off';
		}

		if ( isset( $options['checks'] ) && is_array( $options['checks'] ) ) {
			foreach ( $options['checks'] as $check ) {
				if ( $check instanceof Check_Base ) {
					$this->checks[] = $check;
				}
			}
		}

		$this->max_bytes   = isset( $options['max_bytes'] ) ? (int) $options['max_bytes'] : self::DEFAULT_MAX_BYTES;
		$this->max_ms      = isset( $options['max_ms'] ) ? (float) $options['max_ms'] : (float) self::DEFAULT_MAX_MS;
		$this->attachments = isset( $options['attachments'] ) && $options['attachments'] instanceof Attachment_Lookup ? $options['attachments'] : new Attachment_Lookup();
		$this->recorder    = new Recorder( false );
		$this->token       = new Token();
	}

	/**
	 * First-activation states: recommended fixes in Preview, the rest Off.
	 *
	 * @param Fix[] $fixes Fix objects.
	 * @return string[] Fix id => state.
	 */
	public static function default_states( array $fixes ): array {
		$states = array();
		foreach ( $fixes as $fix ) {
			if ( $fix instanceof Fix ) {
				$states[ $fix->id() ] = $fix->recommended() ? 'preview' : 'off';
			}
		}
		return $states;
	}

	/**
	 * Repairs a document or fragment, or returns it unchanged.
	 *
	 * @param string $html  HTML to repair.
	 * @param string $mode  One of the MODE_* constants.
	 * @param string $scope SCOPE_PAGE for a whole document, SCOPE_CONTENT for a fragment.
	 * @return string The repaired HTML, or the input byte for byte.
	 */
	public function run( string $html, string $mode = self::MODE_VISITOR, string $scope = self::SCOPE_PAGE ): string {
		$this->recorder = new Recorder( self::MODE_VISITOR !== $mode );
		$this->recorder->set_engine(
			array(
				'scope'         => $scope,
				'mode'          => $mode,
				'ms'            => 0.0,
				'bytes'         => strlen( $html ),
				'wp'            => get_bloginfo( 'version' ),
				'tokens'        => 0,
				'openers'       => 0,
				'openers_apply' => null,
				'edits_planned' => 0,
				'edits_applied' => 0,
			)
		);

		if ( '' === $html || ! isset( self::MODE_STATES[ $mode ] ) || ( self::SCOPE_PAGE !== $scope && self::SCOPE_CONTENT !== $scope ) ) {
			return $html;
		}

		$fixes  = $this->active_fixes( $mode, $scope );
		$checks = $this->active_checks( $mode, $scope );
		if ( empty( $fixes ) && empty( $checks ) ) {
			return $html;
		}

		$start = hrtime( true );
		try {
			$output = $this->process( $html, $mode, $scope, $fixes, $checks, $start );
		} catch ( Throwable $throwable ) {
			$this->recorder->fallback( 'exception', get_class( $throwable ) );
			$output = null;
		}
		$this->end_run();
		$this->recorder->set_engine( array( 'ms' => round( ( hrtime( true ) - $start ) / 1e6, 3 ) ) );

		if ( null === $output ) {
			$this->recorder->discard_changes();
			return $html;
		}
		return $output;
	}

	/**
	 * Changes, findings, fallback and timing of the last run.
	 *
	 * @return Recorder
	 */
	public function recorder(): Recorder {
		return $this->recorder;
	}

	/**
	 * The fixes, by id.
	 *
	 * @return Fix[]
	 */
	public function fixes(): array {
		return $this->fixes;
	}

	/**
	 * The state of a fix.
	 *
	 * @param string $fix_id Fix id.
	 * @return string
	 */
	public function state_of( string $fix_id ): string {
		return isset( $this->states[ $fix_id ] ) ? $this->states[ $fix_id ] : 'off';
	}

	/**
	 * The Media Library lookup shared by this engine's runs.
	 *
	 * @return Attachment_Lookup
	 */
	public function attachments(): Attachment_Lookup {
		return $this->attachments;
	}

	/**
	 * Creates the processor for a pass. Both passes read the same input.
	 *
	 * @param string $html Input HTML.
	 * @param string $pass 'analyse' or 'apply'.
	 * @return WP_HTML_Tag_Processor
	 */
	protected function create_processor( string $html, string $pass ): WP_HTML_Tag_Processor {
		unset( $pass );
		return new WP_HTML_Tag_Processor( $html );
	}

	/**
	 * Steps 1 to 5 of blueprint section 3.2. Returns null after recording a fallback.
	 *
	 * @param string       $html   Input.
	 * @param string       $mode   Mode.
	 * @param string       $scope  Scope.
	 * @param Fix[]        $fixes  Active fixes.
	 * @param Check_Base[] $checks Active checks.
	 * @param int|float    $start  hrtime() at the start of the run.
	 * @return string|null
	 */
	private function process( string $html, string $mode, string $scope, array $fixes, array $checks, $start ) {
		$max_bytes = (int) apply_filters( 'softglaze_a11y_max_bytes', $this->max_bytes );
		$max_ms    = (float) apply_filters( 'softglaze_a11y_max_ms', $this->max_ms );

		if ( strlen( $html ) > $max_bytes ) {
			$this->recorder->fallback( 'size' );
			return null;
		}
		// The HTML API assumes UTF-8; anything else is left alone.
		if ( 1 !== preg_match( '//u', $html ) ) {
			$this->recorder->fallback( 'encoding' );
			return null;
		}

		$deadline    = $start + (int) round( $max_ms * 1000000 );
		$this->index = new Document_Index( $scope, $mode, $this->attachments );
		$this->plan  = new Plan();

		$openers = $this->analyse( $html, $fixes, $checks, $deadline );
		if ( null === $openers ) {
			return null;
		}

		// The naming facts and frames are not needed after the fixes' finish(); proposals not after resolve(). Free them early.
		$this->index = null;
		$this->token->clear();
		$apply      = $this->resolve( $mode );
		$this->plan = null;
		if ( empty( $apply ) ) {
			return $html;
		}

		return $this->apply( $html, $apply, $openers, $deadline );
	}

	/**
	 * The opener predicate shared by both passes. Changing it in one pass only
	 * would let ordinals drift, so both call this method.
	 *
	 * @param WP_HTML_Tag_Processor $processor Processor on a token.
	 * @param string|null           $type      get_token_type() of that token.
	 * @return bool
	 */
	private static function is_opener( WP_HTML_Tag_Processor $processor, $type ): bool {
		return '#tag' === $type && ! $processor->is_tag_closer();
	}

	/**
	 * Fixes analysed in this mode and scope, in priority order.
	 *
	 * @param string $mode  Mode.
	 * @param string $scope Scope.
	 * @return Fix[]
	 */
	private function active_fixes( string $mode, string $scope ): array {
		$states = self::MODE_STATES[ $mode ]['analyse'];
		$order  = array();
		$active = array();
		$i      = 0;
		foreach ( $this->fixes as $id => $fix ) {
			if ( ! in_array( $this->states[ $id ], $states, true ) || ! in_array( $scope, $fix->scopes(), true ) ) {
				continue;
			}
			$active[ $id ] = $fix;
			$order[ $id ]  = array( $fix instanceof Fix_Base ? $fix->priority() : 10, $i++ );
		}
		uksort(
			$active,
			static function ( $a, $b ) use ( $order ) {
				return $order[ $a ] <=> $order[ $b ];
			}
		);
		return array_values( $active );
	}

	/**
	 * Checks that run in this mode and scope.
	 *
	 * @param string $mode  Mode.
	 * @param string $scope Scope.
	 * @return Check_Base[]
	 */
	private function active_checks( string $mode, string $scope ): array {
		if ( ! isset( self::MODE_CHECKS[ $mode ] ) ) {
			return array();
		}
		$active = array();
		foreach ( $this->checks as $check ) {
			if ( in_array( $scope, $check->scopes(), true ) ) {
				$active[] = $check;
			}
		}
		return $active;
	}

	/**
	 * The analyse pass. Returns the opener count, or null after recording a fallback.
	 *
	 * @param string       $html     Input.
	 * @param Fix[]        $fixes    Active fixes.
	 * @param Check_Base[] $checks   Active checks.
	 * @param int|float    $deadline hrtime() value after which the run gives up.
	 * @return int|null
	 */
	private function analyse( string $html, array $fixes, array $checks, $deadline ) {
		$this->stack               = array();
		$this->open_tags           = array();
		$this->collecting          = 0;
		$this->containers          = array();
		$this->hidden_open         = 0;
		$this->implied_open        = 0;
		$this->names               = false;
		$this->landmarks           = false;
		$this->controls            = false;
		$this->listeners           = array();
		$this->listen_all          = array();
		$this->hidden_listeners    = array();
		$this->attribute_listeners = array();
		$this->check_listeners     = array();
		$this->check_all           = array();
		$this->attribute_checks    = array();
		$this->dispatch            = array(
			'fixes'  => array(),
			'checks' => array(),
			'hidden' => array(),
		);

		foreach ( $fixes as $fix ) {
			$tags = array();
			if ( $fix instanceof Fix_Base ) {
				$fix->reset();
				$tags            = $fix->tags();
				$this->names     = $this->names || $fix->uses_names();
				$this->landmarks = $this->landmarks || $fix->uses_landmarks();
				$this->controls  = $this->controls || $fix->uses_controls();
			}
			if ( empty( $tags ) ) {
				$this->listen_all[] = $fix;
				continue;
			}
			if ( array( Fix_Base::FINISH_ONLY ) === $tags ) {
				continue;
			}
			foreach ( $tags as $tag ) {
				if ( Fix_Base::IN_HIDDEN === $tag ) {
					$this->hidden_listeners[] = $fix;
				} elseif ( 0 === strpos( $tag, Fix_Base::WITH_ATTRIBUTE ) ) {
					$this->attribute_listeners[ strtolower( substr( $tag, 1 ) ) ][] = $fix;
				} else {
					$this->listeners[ strtoupper( $tag ) ][] = $fix;
				}
			}
		}
		foreach ( $checks as $check ) {
			$check->reset();
			$tags            = $check->tags();
			$this->names     = $this->names || $check->uses_names();
			$this->landmarks = $this->landmarks || $check->uses_landmarks();
			$this->controls  = $this->controls || $check->uses_controls();
			if ( empty( $tags ) ) {
				$this->check_all[] = $check;
				continue;
			}
			if ( array( Fix_Base::FINISH_ONLY ) === $tags ) {
				continue;
			}
			foreach ( $tags as $tag ) {
				if ( 0 === strpos( $tag, Fix_Base::WITH_ATTRIBUTE ) ) {
					$this->attribute_checks[ strtolower( substr( $tag, 1 ) ) ][] = $check;
				} else {
					$this->check_listeners[ strtoupper( $tag ) ][] = $check;
				}
			}
		}

		$this->plain       = array();
		$this->interesting = array(
			'id'          => true,
			'aria-hidden' => true,
			'aria-label'  => true,
			'hidden'      => true,
		);
		if ( $this->names || $this->landmarks ) {
			$this->interesting['role'] = true;
		}
		if ( $this->controls ) {
			$this->interesting['aria-controls'] = true;
		}
		foreach ( array_keys( $this->attribute_listeners + $this->attribute_checks ) as $attribute ) {
			$this->interesting[ $attribute ] = true;
		}

		$processor = $this->create_processor( $html, 'analyse' );
		$ordinal   = -1;
		$tokens    = 0;

		while ( $processor->next_token() ) {
			++$tokens;
			if ( 0 === $tokens % self::CLOCK_EVERY && hrtime( true ) > $deadline ) {
				$this->recorder->fallback( 'time' );
				return null;
			}

			$type = $processor->get_token_type();
			if ( '#text' === $type ) {
				if ( $this->collecting > 0 ) {
					$this->add_text( $processor->get_modifiable_text() );
				}
				continue;
			}
			if ( '#tag' !== $type ) {
				continue;
			}

			$name = (string) $processor->get_token_name();
			if ( ! self::is_opener( $processor, $type ) ) {
				if ( $this->implied_open > 0 ) {
					$this->implied_ends_by_end_tag( $name );
				}
				// Most end tags close nothing the pass tracks.
				if ( ! empty( $this->open_tags[ $name ] ) || isset( self::HEADINGS[ $name ] ) ) {
					$this->close_tag( $name );
				}
				continue;
			}

			++$ordinal;
			if ( $this->implied_open > 0 ) {
				$this->implied_ends_by_start_tag( $processor, $name );
			}
			$this->open_tag( $processor, $ordinal, $name );
		}

		$this->recorder->set_engine(
			array(
				'tokens'  => $tokens,
				'openers' => $ordinal + 1,
			)
		);

		if ( $processor->paused_at_incomplete_token() ) {
			$this->recorder->fallback( 'incomplete' );
			return null;
		}

		// Elements still open at the end of the input close here; fixes can tell them from closed ones.
		foreach ( $this->stack as $frame ) {
			$frame->open_at_end = true;
		}
		while ( ! empty( $this->stack ) ) {
			$this->pop_to( count( $this->stack ) - 1 );
		}

		// Ids and label[for] targets may appear after the element that uses them: names are complete only now.
		if ( $this->names ) {
			foreach ( $this->index->names() as $accessible ) {
				$accessible->resolve( $this->index );
			}
		}

		foreach ( $fixes as $fix ) {
			if ( $fix instanceof Fix_Base ) {
				$fix->finish( $this->index, $this->plan, $this->recorder );
			}
		}
		foreach ( $checks as $check ) {
			$check->finish( $this->index, $this->recorder );
		}

		if ( hrtime( true ) > $deadline ) {
			$this->recorder->fallback( 'time' );
			return null;
		}

		return $ordinal + 1;
	}

	/**
	 * Handles a tag opener: index, implicit closes, text sources, naming
	 * facts, stack, dispatch.
	 *
	 * @param WP_HTML_Tag_Processor $processor Processor on the opener.
	 * @param int                   $ordinal   Opener ordinal.
	 * @param string                $name      Upper-case tag name.
	 * @return void
	 */
	private function open_tag( WP_HTML_Tag_Processor $processor, int $ordinal, string $name ): void {
		// Attribute names once per opener; values are read only for the attributes present.
		$present = $processor->get_attribute_names_with_prefix( '' );
		$present = empty( $present ) ? array() : array_flip( $present );

		$plain = isset( $this->plain[ $name ] ) ? $this->plain[ $name ] : $this->is_plain( $name );
		if ( $plain && ( 0 === $this->hidden_open || empty( $this->hidden_listeners ) ) ) {
			foreach ( $present as $attribute => $position ) {
				if ( isset( $this->interesting[ $attribute ] ) ) {
					$plain = false;
					break;
				}
			}
			if ( $plain ) {
				$this->open_plain( $processor, $name );
				return;
			}
		}

		$id = '';
		if ( isset( $present['id'] ) ) {
			$id = $processor->get_attribute( 'id' );
			$id = is_string( $id ) ? trim( $id ) : '';
		}
		$this->index->add_opener( $name, $id );
		$for = '';
		if ( 'LABEL' === $name ) {
			if ( isset( $present['for'] ) ) {
				$for = $processor->get_attribute( 'for' );
				$for = is_string( $for ) ? trim( $for ) : '';
			}
			$this->index->add_label_for( $for );
		}

		// The parser closes an open link or button when another one starts, and a heading when a heading starts.
		if ( ( 'A' === $name || 'BUTTON' === $name ) && ! empty( $this->open_tags[ $name ] ) ) {
			$this->close_tag( $name );
		} elseif ( isset( self::HEADINGS[ $name ] ) && ! empty( $this->stack ) && isset( self::HEADINGS[ end( $this->stack )->tag ] ) ) {
			$this->pop_to( count( $this->stack ) - 1 );
		}
		$depth = count( $this->stack );
		if ( $depth > 0 && 'BR' !== $name && 'WBR' !== $name ) {
			++$this->stack[ $depth - 1 ]->elements;
		}

		$in_svg = ! empty( $this->open_tags['SVG'] );
		$hidden = false;
		if ( isset( $present['aria-hidden'] ) ) {
			$aria_hidden = $processor->get_attribute( 'aria-hidden' );
			$hidden      = is_string( $aria_hidden ) && 'true' === strtolower( trim( $aria_hidden ) );
		}
		$barrier = $hidden;
		$role    = '';
		if ( isset( $present['role'] ) && ( $this->names || $this->landmarks ) ) {
			$role = $processor->get_attribute( 'role' );
			$role = is_string( $role ) ? strtolower( trim( $role ) ) : '';
		}
		if ( $this->names && '' !== $id ) {
			$this->index->set_menu( $id, self::is_menu( $processor, $name, $role ) );
		}
		if ( $this->landmarks && ( '' !== $role || 'MAIN' === $name || 'NAV' === $name ) ) {
			$this->landmark_facts( $processor, $ordinal, $name, $role, $id, $hidden );
		}
		if ( $this->controls && isset( $present['aria-controls'] ) ) {
			$controls = $processor->get_attribute( 'aria-controls' );
			if ( is_string( $controls ) ) {
				$this->index->add_controls( $controls );
			}
		}

		if ( $this->collecting > 0 && ! $hidden ) {
			$own_label = '';
			if ( $this->names ) {
				if ( isset( $present['aria-label'] ) ) {
					$own_label = $processor->get_attribute( 'aria-label' );
					$own_label = is_string( $own_label ) ? trim( $own_label ) : '';
				}
				// Hidden content is not part of the name of the control around it.
				$barrier = '' === $own_label && isset( $present['hidden'] );
			}
			if ( $barrier ) {
				$own_label = '';
			} elseif ( '' !== $own_label ) {
				// An element's aria-label stands in for its content in the name of the control around it.
				$this->add_text( ' ' . $own_label . ' ' );
				$barrier = true;
			} elseif ( 'IMG' === $name ) {
				if ( isset( $present['alt'] ) ) {
					$alt = $processor->get_attribute( 'alt' );
					if ( is_string( $alt ) ) {
						$this->add_text( ' ' . $alt . ' ' );
					}
				}
			} elseif ( 'TITLE' === $name && $in_svg ) {
				$this->add_text( ' ' . $processor->get_modifiable_text() . ' ' );
			}
		}
		if ( 'TITLE' === $name && $in_svg ) {
			$this->innermost( 'SVG' )->has_svg_title = true;
		}

		$collects = isset( self::TRACKED[ $name ] ) ? self::TRACKED[ $name ] : null;
		if ( null === $collects && isset( self::TRACKED_IN_CONTROLS[ $name ] ) && $this->in_control() ) {
			$collects = true;
		}
		$accessible = null;
		if ( $this->names ) {
			if ( null === $collects ) {
				if ( 'button' === $role || 'link' === $role || ( 'SPAN' === $name && '' !== $id ) ) {
					// Controls by role collect their content; spans with an id are typical aria-labelledby targets.
					$collects = true;
				} elseif ( 'SELECT' === $name ) {
					// Option text is not part of a wrapping label's text.
					$collects = false;
					$barrier  = true;
				} elseif ( 'SEARCH' === $name ) {
					$collects = false;
				}
			}
			if ( 'button' === $role || 'link' === $role || isset( self::NAMEABLE[ $name ] ) ) {
				$accessible = $this->name_facts( $processor, $ordinal, $name, $role, $hidden, $present );
			}
			if ( isset( self::CONTROL_PARTS[ $name ] ) && $this->in_control() ) {
				$this->count_control_part( $processor, $name, $present );
			}
		}

		$tracked  = null !== $collects;
		$can_open = ! isset( self::VOID[ $name ] ) && ! isset( self::RAW[ $name ] )
			&& ! ( ( 'SVG' === $name || $in_svg ) && $processor->has_self_closing_flag() )
			&& ! ( 'FORM' === $name && ! empty( $this->open_tags['FORM'] ) );

		$element   = null;
		$listeners = isset( $this->listeners[ $name ] ) || ! empty( $this->listen_all ) ? $this->listeners_for( $name ) : array();
		$checks    = isset( $this->check_listeners[ $name ] ) || ! empty( $this->check_all ) ? $this->checks_for( $name ) : array();
		foreach ( $this->attribute_listeners as $attribute => $wanting ) {
			if ( isset( $present[ $attribute ] ) ) {
				$listeners = self::add_by_attribute( $listeners, $this->attribute_listeners, $present );
				break;
			}
		}
		foreach ( $this->attribute_checks as $attribute => $wanting ) {
			if ( isset( $present[ $attribute ] ) ) {
				$checks = self::add_by_attribute( $checks, $this->attribute_checks, $present );
				break;
			}
		}
		// Read before this element's own frame is pushed, so hidden_open counts its ancestors only.
		$in_hidden = ! empty( $this->hidden_listeners ) && ( $hidden || $this->hidden_open > 0 ) ? $this->hidden_for( $name ) : array();
		$ancestors = $this->stack;

		if ( $can_open && ( $tracked || $barrier ) ) {
			$element            = new Element( $name, $ordinal, true === $collects, $hidden );
			$element->barrier   = $barrier;
			$element->id        = $id;
			$element->label_for = $for;
			$element->search    = 'SEARCH' === $name || ( 'FORM' === $name && ( 'search' === $role || ! empty( $this->open_tags['SEARCH'] ) ) );
			if ( null !== $accessible ) {
				$element->accessible = $accessible;
				$accessible->frame   = $element;
				if ( $accessible->is_container() ) {
					$this->containers[] = $accessible;
				}
			}
			$this->stack[]            = $element;
			$this->open_tags[ $name ] = isset( $this->open_tags[ $name ] ) ? $this->open_tags[ $name ] + 1 : 1;
			if ( $element->collects_text ) {
				++$this->collecting;
			}
			if ( $hidden ) {
				++$this->hidden_open;
			}
			if ( isset( self::IMPLIED_END[ $name ] ) ) {
				++$this->implied_open;
			}
		} elseif ( $can_open && ! empty( $this->open_tags[ $name ] ) ) {
			// An untracked element with the same name as an open frame: its end tag must not close the frame.
			++$this->innermost( $name )->nested;
		}

		if ( empty( $listeners ) && empty( $checks ) && empty( $in_hidden ) ) {
			return;
		}
		$this->token->set_opener( $processor, $ordinal, $name, $element, $ancestors, $present );
		if ( null !== $accessible ) {
			$this->token->set_accessible( $accessible );
		}
		foreach ( $listeners as $fix ) {
			$fix->analyse( $this->token, $this->index, $this->plan, $this->recorder );
		}
		foreach ( $in_hidden as $fix ) {
			// Each fix sees an opener once, also when an attribute already brought it here.
			if ( empty( $this->attribute_listeners ) || ! in_array( $fix, $listeners, true ) ) {
				$fix->analyse( $this->token, $this->index, $this->plan, $this->recorder );
			}
		}
		foreach ( $checks as $check ) {
			$check->analyse( $this->token, $this->index, $this->recorder );
		}
	}

	/**
	 * Whether openers of a tag are plain in this run: never tracked, never
	 * named, never part of a control's facts, and seen by no fix or check by
	 * their tag. Such an opener without an interesting attribute only counts.
	 *
	 * @param string $name Upper-case tag name.
	 * @return bool
	 */
	private function is_plain( string $name ): bool {
		$plain = ! isset( self::TRACKED[ $name ] )
			&& ! isset( self::TRACKED_IN_CONTROLS[ $name ] )
			&& ! isset( self::NAMEABLE[ $name ] )
			&& ! isset( self::CONTROL_PARTS[ $name ] )
			&& ! isset( self::HEADINGS[ $name ] )
			&& ! isset( self::PLAIN_NEVER[ $name ] )
			&& ! isset( $this->listeners[ $name ] )
			&& ! isset( $this->check_listeners[ $name ] )
			&& empty( $this->listen_all )
			&& empty( $this->check_all );

		$this->plain[ $name ] = $plain;
		return $plain;
	}

	/**
	 * What open_tag() does for a plain opener without an interesting attribute:
	 * count it, and keep the end tags of nested same-name elements apart.
	 *
	 * @param WP_HTML_Tag_Processor $processor Processor on the opener.
	 * @param string                $name      Upper-case tag name.
	 * @return void
	 */
	private function open_plain( WP_HTML_Tag_Processor $processor, string $name ): void {
		$this->index->add_opener( $name, '' );
		$depth = count( $this->stack );
		if ( 0 === $depth ) {
			return;
		}
		if ( 'BR' !== $name && 'WBR' !== $name ) {
			++$this->stack[ $depth - 1 ]->elements;
		}
		if (
			! empty( $this->open_tags[ $name ] )
			&& ! isset( self::VOID[ $name ] ) && ! isset( self::RAW[ $name ] )
			&& ! ( ! empty( $this->open_tags['SVG'] ) && $processor->has_self_closing_flag() )
		) {
			// An untracked element with the same name as an open frame: its end tag must not close the frame.
			++$this->innermost( $name )->nested;
		}
	}

	/**
	 * Builds the naming facts of a link, button, field or role="button" /
	 * role="link" element, once, for every fix and check of the run.
	 *
	 * @param WP_HTML_Tag_Processor $processor Processor on the opener.
	 * @param int                   $ordinal   Opener ordinal.
	 * @param string                $name      Upper-case tag name.
	 * @param string                $role      Lower-case explicit role, or ''.
	 * @param bool                  $hidden    Whether the opener carries aria-hidden="true".
	 * @param array                 $present   Lower-case names of the attributes present, as keys.
	 * @return Accessible_Name|null
	 */
	private function name_facts( WP_HTML_Tag_Processor $processor, int $ordinal, string $name, string $role, bool $hidden, array $present ) {
		$type = '';
		if ( 'INPUT' === $name ) {
			$type = isset( $present['type'] ) ? $processor->get_attribute( 'type' ) : '';
			$type = is_string( $type ) && '' !== trim( $type ) ? strtolower( trim( $type ) ) : 'text';
		}
		$kind = Accessible_Name::kind_for( $name, $role, $type, isset( $present['href'] ) );
		if ( '' === $kind ) {
			return null;
		}

		// Values are decoded only for the attributes this kind keeps. The keys come from the
		// constant list (shared strings), not from the processor: thousands of records stay small.
		$attributes = array();
		foreach ( Accessible_Name::attributes_for( $kind ) as $attribute => $wanted ) {
			if ( $wanted && isset( $present[ $attribute ] ) ) {
				$value                    = $processor->get_attribute( $attribute );
				$attributes[ $attribute ] = is_string( $value ) ? $value : '';
			}
		}

		$accessible            = new Accessible_Name( $ordinal, $name, $role, $kind, $attributes );
		$accessible->hidden    = $accessible->hidden || $hidden || $this->hidden_open > 0;
		$accessible->in_hidden = $this->hidden_open > 0;
		$accessible->label     = empty( $this->open_tags['LABEL'] ) ? null : $this->innermost( 'LABEL' );
		$accessible->article   = empty( $this->open_tags['ARTICLE'] ) ? null : $this->innermost( 'ARTICLE' );
		$accessible->form      = empty( $this->open_tags['FORM'] ) ? null : $this->innermost( 'FORM' );
		if ( null === $accessible->form && ! empty( $this->open_tags['SEARCH'] ) ) {
			$accessible->form = $this->innermost( 'SEARCH' );
		}
		if ( null !== $accessible->form && ( 'search' === $accessible->type || ( 'INPUT' === $name && 's' === $accessible->attr( 'name' ) ) ) ) {
			$accessible->form->search = true;
		}
		if ( $this->recorder->is_recording() ) {
			$this->token->set_opener( $processor, $ordinal, $name, null, array() );
			$accessible->sig = $this->token->signature();
		}
		$this->index->add_name( $accessible );
		return $accessible;
	}

	/**
	 * Records a main or navigation landmark: a main or nav element without a
	 * different explicit role, or any element whose first role token is main
	 * or navigation.
	 *
	 * @param WP_HTML_Tag_Processor $processor Processor on the opener.
	 * @param int                   $ordinal   Opener ordinal.
	 * @param string                $name      Upper-case tag name.
	 * @param string                $role      Lower-case explicit role, or ''.
	 * @param string                $id        Trimmed id, or ''.
	 * @param bool                  $hidden    Whether the opener carries aria-hidden="true".
	 * @return void
	 */
	private function landmark_facts( WP_HTML_Tag_Processor $processor, int $ordinal, string $name, string $role, string $id, bool $hidden ): void {
		if ( '' !== $role ) {
			$tokens   = preg_split( '/\s+/', $role, 2 );
			$first    = is_array( $tokens ) ? $tokens[0] : '';
			$landmark = 'main' === $first || 'navigation' === $first ? $first : '';
		} else {
			$landmark = 'MAIN' === $name ? 'main' : ( 'NAV' === $name ? 'navigation' : '' );
		}
		if ( '' === $landmark ) {
			return;
		}

		$label      = $processor->get_attribute( 'aria-label' );
		$labelledby = $processor->get_attribute( 'aria-labelledby' );
		$signature  = '';
		if ( $this->recorder->is_recording() ) {
			$this->token->set_opener( $processor, $ordinal, $name, null, array() );
			$signature = $this->token->signature();
		}
		$this->index->add_landmark(
			array(
				'ordinal'    => $ordinal,
				'tag'        => $name,
				'sig'        => $signature,
				'role'       => $landmark,
				'id'         => $id,
				'label'      => is_string( $label ) ? trim( $label ) : '',
				'labelledby' => is_string( $labelledby ) ? trim( $labelledby ) : '',
				'hidden'     => $hidden || $this->hidden_open > 0 || null !== $processor->get_attribute( 'hidden' ),
			)
		);
	}

	/**
	 * Counts an image or SVG inside the innermost control, and keeps the class
	 * names of icon elements (i, span, svg) for the label maps.
	 *
	 * @param WP_HTML_Tag_Processor $processor Processor on the opener.
	 * @param string                $name      Upper-case tag name.
	 * @param array                 $present   Lower-case names of the attributes present, as keys.
	 * @return void
	 */
	private function count_control_part( WP_HTML_Tag_Processor $processor, string $name, array $present ): void {
		$control = end( $this->containers );
		if ( false === $control ) {
			return;
		}
		if ( 'IMG' === $name ) {
			++$control->images;
			if ( ! isset( $present['alt'] ) ) {
				++$control->images_without_alt;
			}
			return;
		}
		if ( 'SVG' === $name ) {
			++$control->svgs;
		}
		if ( ! isset( $present['class'] ) ) {
			return;
		}
		$class = $processor->get_attribute( 'class' );
		if ( ! is_string( $class ) || count( $control->icon_classes ) >= self::ICON_CLASS_LIMIT ) {
			return;
		}
		foreach ( (array) preg_split( '/\s+/', strtolower( $class ), -1, PREG_SPLIT_NO_EMPTY ) as $class_name ) {
			$control->icon_classes[] = (string) $class_name;
		}
	}

	/**
	 * Whether an element with an id is a nav element or a menu list (for aria-controls targets).
	 *
	 * @param WP_HTML_Tag_Processor $processor Processor on the opener.
	 * @param string                $name      Upper-case tag name.
	 * @param string                $role      Lower-case explicit role, or ''.
	 * @return bool
	 */
	private static function is_menu( WP_HTML_Tag_Processor $processor, string $name, string $role ): bool {
		if ( 'NAV' === $name || 'navigation' === $role || 'menu' === $role || 'menubar' === $role ) {
			return true;
		}
		if ( 'UL' !== $name && 'OL' !== $name && 'DIV' !== $name ) {
			return false;
		}
		$class = $processor->get_attribute( 'class' );
		return is_string( $class ) && 1 === preg_match( '/(?:^|[\s_-])(?:menu|nav|navigation|navbar)(?:$|[\s_-])/i', $class );
	}

	/**
	 * Handles an end tag for a tracked element.
	 *
	 * @param string $name Upper-case tag name.
	 * @return void
	 */
	private function close_tag( string $name ): void {
		$is_heading = isset( self::HEADINGS[ $name ] );
		if ( ! $is_heading && empty( $this->open_tags[ $name ] ) ) {
			return;
		}
		for ( $i = count( $this->stack ) - 1; $i >= 0; $i-- ) {
			$frame = $this->stack[ $i ];
			if ( $frame->tag === $name || ( $is_heading && isset( self::HEADINGS[ $frame->tag ] ) ) ) {
				if ( $frame->nested > 0 ) {
					--$frame->nested;
					return;
				}
				$this->pop_to( $i );
				return;
			}
		}
	}

	/**
	 * Closes the frame at a stack position and every frame inside it, innermost first.
	 *
	 * @param int $position Stack position.
	 * @return void
	 */
	private function pop_to( int $position ): void {
		for ( $i = count( $this->stack ) - 1; $i >= $position; $i-- ) {
			$frame = array_pop( $this->stack );
			--$this->open_tags[ $frame->tag ];
			if ( $frame->collects_text ) {
				--$this->collecting;
			}
			if ( $frame->aria_hidden ) {
				--$this->hidden_open;
			}
			if ( isset( self::IMPLIED_END[ $frame->tag ] ) ) {
				--$this->implied_open;
			}
			if ( $this->names ) {
				$this->closed_for_names( $frame );
			}
			$listeners = isset( $this->dispatch['fixes'][ $frame->tag ] ) ? $this->dispatch['fixes'][ $frame->tag ] : $this->listeners_for( $frame->tag );
			$checks    = isset( $this->dispatch['checks'][ $frame->tag ] ) ? $this->dispatch['checks'][ $frame->tag ] : $this->checks_for( $frame->tag );
			if ( empty( $listeners ) && empty( $checks ) ) {
				continue;
			}
			$this->token->set_closer( $frame, $this->stack );
			foreach ( $listeners as $fix ) {
				$fix->analyse( $this->token, $this->index, $this->plan, $this->recorder );
			}
			foreach ( $checks as $check ) {
				$check->analyse( $this->token, $this->index, $this->recorder );
			}
		}
	}

	/**
	 * What the naming facts learn when a frame closes: the text of elements
	 * with an id (aria-labelledby targets), label text by for, and the first
	 * heading of an article.
	 *
	 * @param Element $frame The closed frame.
	 * @return void
	 */
	private function closed_for_names( Element $frame ): void {
		if ( null !== $frame->accessible ) {
			if ( $frame->accessible->is_container() ) {
				array_pop( $this->containers );
			}
			$frame->accessible->close( $frame->text );
		}
		if ( ! $frame->collects_text ) {
			return;
		}
		// Raw text; normalised only when an aria-labelledby or label[for] asks for it.
		if ( '' !== $frame->id ) {
			$this->index->set_id_text( $frame->id, $frame->text );
		}
		if ( 'LABEL' === $frame->tag && '' !== $frame->label_for ) {
			$this->index->add_label_text( $frame->label_for, $frame->text );
		}
		if ( isset( self::HEADINGS[ $frame->tag ] ) ) {
			$article = $this->innermost_open( 'ARTICLE' );
			if ( null !== $article && null === $article->heading ) {
				$article->heading = Element::without_private_use( $frame->text );
			}
		}
	}

	/**
	 * Appends text to the open frames that collect text, innermost first,
	 * stopping after a frame whose content does not count outside it.
	 *
	 * @param string $text Decoded text.
	 * @return void
	 */
	private function add_text( string $text ): void {
		for ( $i = count( $this->stack ) - 1; $i >= 0; $i-- ) {
			$frame = $this->stack[ $i ];
			if ( $frame->collects_text ) {
				$frame->add_text( $text );
			} elseif ( 'SVG' === $frame->tag && ! $frame->has_text && '' !== Element::without_private_use( $text ) ) {
				// Text inside an SVG (text, foreignObject) is part of the name of the control around it.
				$frame->has_text = true;
			}
			if ( $frame->barrier ) {
				return;
			}
		}
	}

	/**
	 * A start tag that ends open frames whose end tag was left out (a new
	 * list item ends the previous one, a block ends a paragraph), and counts
	 * the containers of their kind opened inside them.
	 *
	 * @param WP_HTML_Tag_Processor $processor Processor on the opener.
	 * @param string                $name      Upper-case tag name.
	 * @return void
	 */
	private function implied_ends_by_start_tag( WP_HTML_Tag_Processor $processor, string $name ): void {
		$opens = ! ( ( 'SVG' === $name || 'MATH' === $name ) && $processor->has_self_closing_flag() );
		for ( $i = count( $this->stack ) - 1; $i >= 0; $i-- ) {
			$frame = $this->stack[ $i ];
			if ( ! isset( self::IMPLIED_END[ $frame->tag ] ) ) {
				continue;
			}
			$rules = self::IMPLIED_END[ $frame->tag ];
			if ( 0 === $frame->inner && isset( $rules['start'][ $name ] ) ) {
				// Frames inside it close with it, as in the parser.
				$this->pop_to( $i );
				continue;
			}
			if ( $opens && isset( $rules['inner'][ $name ] ) ) {
				++$frame->inner;
			}
		}
	}

	/**
	 * An end tag that ends open frames whose own end tag was left out: the
	 * end of the list, table or select around them, or of a block around a
	 * paragraph. The end tag of a container counted by
	 * implied_ends_by_start_tag() only closes that container.
	 *
	 * @param string $name Upper-case tag name.
	 * @return void
	 */
	private function implied_ends_by_end_tag( string $name ): void {
		for ( $i = count( $this->stack ) - 1; $i >= 0; $i-- ) {
			$frame = $this->stack[ $i ];
			if ( $name === $frame->tag || ! isset( self::IMPLIED_END[ $frame->tag ] ) ) {
				continue;
			}
			$rules = self::IMPLIED_END[ $frame->tag ];
			if ( $frame->inner > 0 ) {
				if ( isset( $rules['inner'][ $name ] ) ) {
					--$frame->inner;
					if ( 0 === $frame->inner ) {
						// Same-name elements inside the closed container closed with it.
						$frame->nested = 0;
					}
				}
				continue;
			}
			if ( isset( $rules['inner'][ $name ] ) || isset( $rules['end'][ $name ] ) ) {
				$this->pop_to( $i );
			}
		}
	}

	/**
	 * Whether a link, button or role="button" / role="link" element is open.
	 *
	 * @return bool
	 */
	private function in_control(): bool {
		return ! empty( $this->open_tags['A'] ) || ! empty( $this->open_tags['BUTTON'] ) || ! empty( $this->containers );
	}

	/**
	 * The innermost open frame with a tag (callers check that one is open).
	 *
	 * @param string $tag Upper-case tag name.
	 * @return Element
	 */
	private function innermost( string $tag ): Element {
		for ( $i = count( $this->stack ) - 1; $i > 0; $i-- ) {
			if ( $tag === $this->stack[ $i ]->tag ) {
				return $this->stack[ $i ];
			}
		}
		return $this->stack[0];
	}

	/**
	 * The innermost open frame with a tag, or null when none is open.
	 *
	 * @param string $tag Upper-case tag name.
	 * @return Element|null
	 */
	private function innermost_open( string $tag ) {
		return empty( $this->open_tags[ $tag ] ) ? null : $this->innermost( $tag );
	}

	/**
	 * Fixes that see a tag.
	 *
	 * @param string $name Upper-case tag name.
	 * @return Fix[]
	 */
	private function listeners_for( string $name ): array {
		if ( ! isset( $this->dispatch['fixes'][ $name ] ) ) {
			$this->dispatch['fixes'][ $name ] = isset( $this->listeners[ $name ] ) ? array_merge( $this->listeners[ $name ], $this->listen_all ) : $this->listen_all;
		}
		return $this->dispatch['fixes'][ $name ];
	}

	/**
	 * Adds the listeners of the attributes an opener carries, each listener once.
	 *
	 * @param array $listeners  Fixes or checks that already see the opener.
	 * @param array $by_name    Lower-case attribute name => fixes or checks.
	 * @param array $present    Lower-case names of the attributes present, as keys.
	 * @return array
	 */
	private static function add_by_attribute( array $listeners, array $by_name, array $present ): array {
		foreach ( $by_name as $attribute => $wanting ) {
			if ( ! isset( $present[ $attribute ] ) ) {
				continue;
			}
			foreach ( $wanting as $listener ) {
				if ( ! in_array( $listener, $listeners, true ) ) {
					$listeners[] = $listener;
				}
			}
		}
		return $listeners;
	}

	/**
	 * Fixes that see a tag in aria-hidden content and do not already see it by its name.
	 *
	 * @param string $name Upper-case tag name.
	 * @return Fix[]
	 */
	private function hidden_for( string $name ): array {
		if ( ! isset( $this->dispatch['hidden'][ $name ] ) ) {
			$by_name = $this->listeners_for( $name );
			$extra   = array();
			foreach ( $this->hidden_listeners as $fix ) {
				if ( ! in_array( $fix, $by_name, true ) ) {
					$extra[] = $fix;
				}
			}
			$this->dispatch['hidden'][ $name ] = $extra;
		}
		return $this->dispatch['hidden'][ $name ];
	}

	/**
	 * Checks that see a tag.
	 *
	 * @param string $name Upper-case tag name.
	 * @return Check_Base[]
	 */
	private function checks_for( string $name ): array {
		if ( ! isset( $this->dispatch['checks'][ $name ] ) ) {
			$this->dispatch['checks'][ $name ] = isset( $this->check_listeners[ $name ] ) ? array_merge( $this->check_listeners[ $name ], $this->check_all ) : $this->check_all;
		}
		return $this->dispatch['checks'][ $name ];
	}

	/**
	 * Step 3: picks one proposal per attribute, records it, and returns the
	 * edits this mode applies, keyed by ordinal.
	 *
	 * @param string $mode Mode.
	 * @return array
	 */
	private function resolve( string $mode ): array {
		$apply_states = self::MODE_STATES[ $mode ]['apply'];
		$done         = array();
		// A proposal applies when its fix's state applies in this mode and the edit it depends on (an earlier ordinal) was applied.
		$applies = function ( $proposal ) use ( $apply_states, &$done ) {
			if ( ! in_array( $this->state_of( $proposal['fix'] ), $apply_states, true ) ) {
				return false;
			}
			$requires = $proposal['requires'];
			return null === $requires || isset( $done[ $requires[0] ][ $requires[1] ] );
		};
		$any     = static function () {
			return true;
		};

		$apply   = array();
		$applied = 0;
		foreach ( $this->plan->grouped() as $ordinal => $attributes ) {
			foreach ( $attributes as $attribute => $proposals ) {
				$winner     = Plan::winner( $proposals, $applies );
				$is_applied = null !== $winner;
				if ( ! $is_applied ) {
					$winner = Plan::winner( $proposals, $any );
				}
				$this->recorder->add_change( $winner['fix'], $this->state_of( $winner['fix'] ), $winner['signature'], $attribute, $winner['before'], $winner['value'], $is_applied );
				if ( $is_applied ) {
					$apply[ $ordinal ]['tag']                      = $winner['tag'];
					$apply[ $ordinal ]['attributes'][ $attribute ] = $winner['value'];
					$done[ $ordinal ][ $attribute ]                = true;
					++$applied;
				}
			}
		}

		$this->recorder->set_engine(
			array(
				'edits_planned' => $this->plan->count(),
				'edits_applied' => $applied,
			)
		);
		return $apply;
	}

	/**
	 * Steps 4 and 5: the apply pass and the sanity check. Returns null after recording a fallback.
	 *
	 * @param string    $html     Input.
	 * @param array     $apply    Edits keyed by ordinal.
	 * @param int       $openers  Opener count of the analyse pass.
	 * @param int|float $deadline hrtime() value after which the run gives up.
	 * @return string|null
	 */
	private function apply( string $html, array $apply, int $openers, $deadline ) {
		$expected = 0;
		foreach ( $apply as $edit ) {
			$expected += count( $edit['attributes'] );
		}

		$processor = $this->create_processor( $html, 'apply' );
		$ordinal   = -1;
		$tokens    = 0;
		$applied   = 0;
		$pending   = 0;

		while ( $processor->next_token() ) {
			++$tokens;
			if ( 0 === $tokens % self::CLOCK_EVERY && hrtime( true ) > $deadline ) {
				$this->recorder->fallback( 'time' );
				return null;
			}
			// Text, comments and other non-tag tokens are never openers; the shared predicate decides for tags.
			$type = $processor->get_token_type();
			if ( '#tag' !== $type || ! self::is_opener( $processor, $type ) ) {
				continue;
			}
			++$ordinal;
			if ( ! isset( $apply[ $ordinal ] ) ) {
				continue;
			}
			if ( $processor->get_token_name() !== $apply[ $ordinal ]['tag'] ) {
				$this->recorder->fallback( 'ordinal' );
				return null;
			}
			foreach ( $apply[ $ordinal ]['attributes'] as $attribute => $value ) {
				if ( ! $processor->set_attribute( $attribute, $value ) ) {
					$this->recorder->fallback( 'apply' );
					return null;
				}
				++$applied;
				++$pending;
			}

			// The processor walks its queue of pending updates on every token; flush it every few edits and after the last.
			if ( $pending >= self::FLUSH_EVERY || $applied === $expected ) {
				$processor->get_updated_html();
				$pending = 0;
			}
		}

		$this->recorder->set_engine( array( 'openers_apply' => $ordinal + 1 ) );

		if ( $processor->paused_at_incomplete_token() ) {
			$this->recorder->fallback( 'incomplete' );
			return null;
		}
		if ( $ordinal + 1 !== $openers ) {
			$this->recorder->fallback( 'ordinal' );
			return null;
		}
		if ( $applied !== $expected ) {
			$this->recorder->fallback( 'apply' );
			return null;
		}

		$output = $processor->get_updated_html();
		if (
			'' === $output
			|| substr_count( $output, '<' ) !== substr_count( $html, '<' )
			|| substr_count( $output, '>' ) !== substr_count( $html, '>' )
		) {
			$this->recorder->fallback( 'sanity' );
			return null;
		}
		return $output;
	}

	/**
	 * Releases per-run references.
	 *
	 * @return void
	 */
	private function end_run(): void {
		$this->token->clear();
		$this->stack        = array();
		$this->open_tags    = array();
		$this->collecting   = 0;
		$this->containers   = array();
		$this->hidden_open  = 0;
		$this->implied_open = 0;
		$this->index        = null;
		$this->plan         = null;
		$this->dispatch     = array(
			'fixes'  => array(),
			'checks' => array(),
			'hidden' => array(),
		);
	}
}
