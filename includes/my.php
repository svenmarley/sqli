<?php

namespace includes;


class my
{
    const  UNIVERSAL_NOT_DEFINED = 'undefined';
    const  DATE_FORMAT = 'Y-m-d H:i:s';

    public static string $debugLogPath = '/data/tmp/error_logs/debug.log';

    /** Master switch for lineout(); when false every call is a no-op (toggled
     *  from the app's System Menu → Debug Logging, spec §12). */
    public static bool $enabled = true;


    /**
     * Open append handles, keyed by path, so a log line is one write rather than
     * an open/write/close.
     *
     * @var array<string, resource>
     */
    private static array $handles = [];

    /**
     * Append one line to a log file, reusing the file handle.
     *
     * **Why this is not `file_put_contents(..., FILE_APPEND)` any more.** That
     * reopens the file on every line, and when the log lives on a Windows drive
     * seen from WSL (`/mnt/d/...`, i.e. drvfs/9p) the *open* is what costs: a line
     * measured at **2.85 ms** through `file_put_contents` against **0.54 ms**
     * through a held handle, where the same write on native ext4 is 8 us. Debug
     * logging is on by default, so that difference is paid by every user on the
     * WSL path whether or not anyone ever reads the file.
     *
     * The handle is flushed on every line on purpose: a debug log is most wanted
     * when the process is wedged or about to die, and buffered lines that never
     * reached the disk are worse than slow ones.
     */
    private static function appendLine( string $path, string $line ): void
    {
        if ( ! isset( self::$handles[ $path ] ) ) {
            $h = @fopen( $path, 'ab' );
            if ( $h === false ) {
                return; // unwritable: stay silent, exactly as the old @-less call did
            }
            self::$handles[ $path ] = $h;
        }
        fwrite( self::$handles[ $path ], $line );
        fflush( self::$handles[ $path ] );
    }

    /**
     * Function to write out to the browser, a file, log file, or VSL.
     *
     *    <br>Example output:
     *    <br>    ACS-ImportMonthlyTrans.php()-->Processing config file [C:/dev/CollegeLoan/config/ACS-CD-Monthly Details.xml]
     *    <br>    ACS-ImportMonthlyTrans.php()-->Processing file [C:/data/CollegeLoan/ACS-Inbound/RunThisAsATest.txt]
     *    <br>    ACS-ImportMonthlyTrans.php()-->Parsing file [...]
     *    <br>    ACS-ImportMonthlyTrans.php()-->Proc ID [8]
     *
     * DIRECTIVES (FILE: / CMD: prefixed strings consumed before label/value alternation):
     *   "FILE:CMDLINE" / "FILE:CMD_LINE"  — write to command line (persists across calls)
     *   "FILE:WEB"                        — write HTML (persists across calls)
     *   "FILE:NONE"                       — suppress output (persists across calls)
     *   "FILE:FILE"                       — write to debug log file (persists across calls)
     *   "FILE:DEBUG_LOG" / "FILE:DEBUGLOG" — write to $debugLogPath (one-shot: always reverts)
     *   "CMD:JSON"                        — emit a JSON object instead of bracket-formatted text
     *   "CMD:STANDOUT"                    — prepend a standout marker to the line
     *   "CMD:NO_DEBUG"                    — suppress debug prefix
     *   "CMD:DEEP_DEBUG"                  — include deep-debug detail
     *   FILE:ERROR_LOG is NOT a valid directive — do not use it.
     *
     * ONE-SHOT MODIFIER (+):
     *   Append + to any FILE: directive to make it one-shot. The destination is
     *   used for this call only, then reverts to whatever it was before the call.
     *   Example: "FILE:CMDLINE+" writes to stdout once, then restores the previous
     *   destination. FILE:DEBUG_LOG is always one-shot (+ is not required).
     *
     * LABEL/VALUE ALTERNATION (applies to all args that are not directives):
     *   Args alternate  label, value, label, value ...
     *   Output format:  label=[value] label=[value]
     *   The FIRST non-directive arg is a plain label (no brackets).
     *   The SECOND non-directive arg is the value shown inside [].
     *   Do NOT pass the calling function name — lineout() captures it automatically.
     *   Do NOT use a standalone 'skip' or 'error' token as a leading label; fold the
     *   context into a single label string so the meaningful variable lands in []:
     *     Good: lineout( 'skip not billable - inspection_id', $id )
     *     Bad:  lineout( 'skip', 'not billable', 'inspection_id', $id )
     *
     * @param mixed ...$args Directives and alternating label/value pairs (see above).
     * @return string|null    The formatted output string, or null if nothing was written.
     */
    public static function lineout( ...$args ): ?string
    {
        if ( !self::$enabled ) {
            return null;
        }
        global $globals;
        static $pid = 0;
        static $outDest = false;
        $sRet = '';
        $outStr = "";
        $aOut = [];
        $bIncludeDebug = true;
        $bIncDeepDebug = false;
        $bJSONOut = false;
        $debug = false;
        $preKey = '';
        $bStandout = false;
        $bStandoutText = str_repeat( '█', 5 );
        $sSaveOldDest = null;
        //    █   ≡   ─   ░    ▒


        if ( $debug ) {
            print_r( $globals );
        }
        if ( !$outDest ) {
            $outDest = lineoutOutType::FILE;
        }
//        if ( isset( $globals[ 'config' ][ 'debug' ][ 'debug_file' ] ) )
//            $outDest = lineoutOutType::FILE;
//        else
//            $outDest = lineoutOutType::CMDLINE;

        $i = 0;
        foreach ( $args as $arg ) {
            if ( ( gettype( $arg ) === "string" ) && ( ( stristr( $arg, "FILE:" ) ) || ( stristr( $arg, "CMD:" ) ) ) ) {
                // One-shot modifier: FILE:XXXX+ reverts to previous destination after this call
                $oneShot = str_ends_with( $arg, '+' );
                $directive = $oneShot ? substr( $arg, 0, -1 ) : $arg;

                if ( $oneShot && $sSaveOldDest === null ) {
                    $sSaveOldDest = $outDest;
                }

                switch ( $directive ) {
                    case "FILE:CMDLINE":
                    case "FILE:CMD_LINE":
                        $outDest = lineoutOutType::CMDLINE;
                        break;

                    case "FILE:WEB":
                        $outDest = lineoutOutType::WEB;
                        break;

                    case "FILE:NONE":
                        $outDest = lineoutOutType::NONE;
                        break;

                    case "FILE:FILE":
                        $outDest = lineoutOutType::FILE;
                        break;

                    case "FILE:DEBUGLOG":
                    case "FILE:DEBUG_LOG":
                        if ( $sSaveOldDest === null ) {
                            $sSaveOldDest = $outDest;
                        }
                        $outDest = lineoutOutType::DEBUG_LOG;
                        break;

                    case "CMD:NO_DEBUG":
                        $bIncludeDebug = false;
                        break;

                    case "CMD:DEEP_DEBUG":
                        $bIncDeepDebug = true;
                        break;
                    case "CMD:JSON":
                        $bIncDeepDebug = false;
                        $bJSONOut = true;
                        break;

                    case 'CMD:STANDOUT':
                        $bStandout = true;
                        break;

                    default:
                        break;
                }
            } else {
                $preType = '';
                if ( $i % 2 ) {
                    if ( $bJSONOut ) {
                        $aOut[ $preKey ] = $arg;
                    } else {
                        $sTemp = self::lineoutFormat( $arg, $preType );
                        if ( ( !$bIncDeepDebug ) && ( !str_starts_with( $preType, 'a' ) ) ) {
                            $preType = '';
                        }
                        $outStr .= "=" . $preType . "[" . $sTemp . "]";
                    }
                } else {
                    if ( $bJSONOut ) {
                        $preKey = $arg;
                    } else {
                        if ( $i ) {
                            $outStr .= ", ";
                        }
                        $outStr .= self::lineoutFormat( $arg );
                    }
                }
                $i++;
            }
        }

        if ( strlen( $outStr ) || count( $aOut ) ) {
            $x = getmypid();
            if ( $x !== $pid ) {
                $pid = $x;
                $out = PHP_EOL . '*';
            } else {
                $out = '*';
            }

            if ( $bIncludeDebug ) {
                // Milliseconds and the pid, because several processes can share
                // one log file: without the pid the lines cannot be attributed,
                // and without sub-second time they cannot be ordered against
                // each other. Both matter for anything timing-shaped.
                $dt = date( self::DATE_FORMAT ) . sprintf( '.%03d', (int) ( ( microtime( true ) - (int) microtime( true ) ) * 1000 ) );
                $callingFunction = self::called_function();
                $out .= "$dt [$x] $callingFunction()-->";
            }
            if ( $bStandout ) {
                $out .= $bStandoutText;
            }

            switch ( $outDest ) {
                case lineoutOutType::WEB:
                    $sRet = $out . $outStr . '<br />';
                    echo $sRet;
                    break;

                case lineoutOutType::CMDLINE:
                    if ( $bJSONOut ) {
                        $sRet = json_encode( $aOut );
                        echo $sRet . PHP_EOL;
                    } else {
                        $sRet = $out . $outStr . PHP_EOL;
                        echo $sRet;
                    }
                    break;

                case lineoutOutType::FILE:
                    $logFile = $globals[ 'config' ][ 'debug' ][ 'debug_file' ] ?? self::$debugLogPath;
                    if ( $bJSONOut ) {
                        $sRet = json_encode( $aOut );
                        self::appendLine( $logFile, $out . $sRet . PHP_EOL );
                    } else {
                        $sRet = $out . $outStr . PHP_EOL;
                        self::appendLine( $logFile, $sRet );
                    }
                    break;

                case lineoutOutType::DEBUG_LOG:
                    $logPath = self::$debugLogPath;
                    $logDir  = dirname( $logPath );
                    if ( ! is_dir( $logDir ) ) {
                        @mkdir( $logDir, 0777, true );
                    }
                    $sRet = $out . $outStr . PHP_EOL;
                    self::appendLine( $logPath, $sRet );
                    break;

                case lineoutOutType::NONE:
                    $sRet = $out . $outStr . PHP_EOL;
                    break;
            }
        }

        if ( $sSaveOldDest ) {
            $outDest = $sSaveOldDest;
        }

        return ( $sRet );
    }


    public static function lineoutFormat( $arg, &$preType = '' ): string
    {
        $s = "";
        $sc1 = "[";
        $sc2 = "]";

        //echo 'gettype ' . gettype( $s2 );
        $gType = gettype( $arg );
        $preType = $gType[ 0 ];
        if ( ( $gType == 'object' ) || ( $gType == 'array' ) ) {
            if ( ( $gType == 'object' ) && ( method_exists( $arg, 'toString' ) ) ) {
                $s .= $arg->toString();
            } else {
                if ( $gType == 'array' ) {
                    $preType = 'a' . count( $arg );
                }

                $sTemp = print_r( $arg, true );
                $sTemp2 = preg_replace( '/\s+/', ' ', str_replace( "\n", "", $sTemp ) );
                $s .= $sc1 . $gType[ 0 ] . $sTemp2 . $sc2;
            }
        } elseif ( $gType == "boolean" ) {
            if ( $arg ) {
                $s .= "bTRUE";
            } else {
                $s .= "bFALSE";
            }
        } elseif ( $arg === null ) {
            $s .= '{null}';
        } elseif ( $gType == 'string' ) {
            if ( $arg === self::UNIVERSAL_NOT_DEFINED ) {
                $preType = '';
            } else {
                $preType = "s" . strlen( $arg );
            }
            $s .= $arg;
        } elseif ( in_array( $gType, array( 'double', 'integer' ) ) ) {
            $s .= $arg;
        } else {
            $s .= "unfound type of: $gType";
        }

        return ( $s );
    }


    public static function called_function(): string
    {
        $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 3 );
        //echo "\ntrace:" . var_dump( $trace );
        $caller_function_name = $trace[ 2 ][ 'function' ] ?? null;
        $caller_class_name = $trace[ 2 ][ 'class' ] ?? null; // For methods within a class
        $caller_file = $trace[ 1 ][ 'file' ] ?? null;
        if ( $caller_file ) {
            $p = pathinfo( $caller_file );
            $caller_file = $p[ 'filename' ] . "." . $p[ 'extension' ];
            $caller_line = $trace[ 1 ][ 'line' ] ?? null;
            $s = $caller_file . "." . $caller_line . "->";
        } else {
            $s = '';
        }


        if ( $caller_class_name ) {
            //echo "This function was called by method: $caller_class_name::$caller_function_name()" . PHP_EOL;
            return $s . "$caller_class_name::$caller_function_name";
        } else {
            //echo "This function was called by function: $caller_function_name()" . PHP_EOL;
            return ( $s . $caller_function_name );
        }
    }

    public static function getVars( $inObject, $elements, $defaults = array(), $trimResult = false )
    {
        $debug = false;
        if ( $debug ) {
            self::lineout( 'inObj', $inObject );
        }
        $ret = [];
        foreach ( $elements as $i => $element ) {
            if ( $debug ) {
                self::lineout( 'element', $element );
            }
            $default = self::getVar( $defaults, $i, null );
            $ret[ $element ] = self::getVar( $inObject, $element, $default, $trimResult );
        }
        return ( $ret );
    }

    /* returns an element from an array or object, or the default
     *
     * @param array|object $inObject
     * @param string $element
     * @param string|int|null $default
     * @param bool $trimResult
     *
     * @return mixed|string
    */
    public static function getVar( $inObject, $element, $default, $trimResult = false )
    {
        $debug = false;
        $debugFile = null;

        if ( $debug ) {
            self::lineout( $debugFile, "element[$element] is object", ( gettype( $inObject ) == 'object' ) );
        }
        if ( gettype( $inObject ) == 'object' ) {
            // convert to array, so we can search case insensitive if needed, using same code below
            $inObject = (array)$inObject;
        }

        $ret = $default;
        if ( is_array( $inObject ) ) {
            // do fastest, most obvious check first, cause 90% of the time this should be good
            if ( isset( $inObject[ $element ] ) ) {
                $ret = $inObject[ $element ];
            } else {
                $aLower = array_change_key_case( $inObject, CASE_LOWER );

                if ( $debug ) {
                    self::lineout( $debugFile, "element[$element] aLower", $aLower );
                }
                if ( isset( $aLower[ $cmdLower = strtolower( $element ) ] ) ) {
                    $ret = $aLower[ $cmdLower ];
                } else {
                    $options = getopt( $element );
                    if ( isset( $options[ $element ] ) ) {
                        $ret = $options[ $element ];
                    }
                }
            }
        }

        if ( $trimResult ) {
            $ret = trim( $ret );
        }

        if ( $debug ) {
            self::lineout( $debugFile, "returning", $ret );
        }
        return ( $ret );
    }
}

final class lineoutOutType
{
    public const CMDLINE   = 'LO_TYPE_CMDLINE';
    public const FILE      = 'LO_TYPE_FILE';
    public const WEB       = 'LO_TYPE_WEB';
    public const NONE      = 'LO_TYPE_NONE';
    public const VSXLOG    = 'LO_TYPE_VSXLOG';
    public const DEBUG_LOG = 'LO_TYPE_DEBUG_LOG';
}