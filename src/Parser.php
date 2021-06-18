<?php

declare(strict_types=1);

namespace Nelexa\NginxParser;

use Nelexa\NginxParser\Exception\NgxParserDirectiveException;
use Nelexa\NginxParser\Exception\NgxParserException;
use Nelexa\NginxParser\Exception\NgxParserIOException;
use Nelexa\NginxParser\Util\FileUtil;

class Parser
{
    /** @var string option name for function that determines what's saved in "callback" */
    public const OPTION_ON_ERROR = 'onError';

    /** @var string option name for parse stops after first error */
    public const OPTION_CATCH_ERRORS = 'catchErrors';

    /** @var string option name for array of directives to exclude from the payload */
    public const OPTION_IGNORE = 'ignore';

    /** @var string option name for including from other files doesn't happen */
    public const OPTION_SINGLE_FILE = 'singleFile';

    /** @var string option name for including comments to json payload */
    public const OPTION_COMMENTS = 'comments';

    /** @var string option name for unrecognized directives raise errors */
    public const OPTION_STRICT = 'strict';

    /** @var string option name for use includes to create a single config obj */
    public const OPTION_COMBINE = 'combine';

    /** @var string option name for runs context analysis on directives */
    public const OPTION_CHECK_CTX = 'checkCtx';

    /** @var string option name for runs arg count analysis on directives */
    public const OPTION_CHECK_ARGS = 'checkArgs';

    public const DEFAULT_OPTIONS = [
        // function that determines what's saved in "callback"
        self::OPTION_ON_ERROR => null, // callable | null
        // if false, parse stops after first error
        self::OPTION_CATCH_ERRORS => true, // bool
        // array of directives to exclude from the payload
        self::OPTION_IGNORE => [], // array
        // if true, including from other files doesn't happen
        self::OPTION_SINGLE_FILE => false, // bool
        // if true, including comments to json payload
        self::OPTION_COMMENTS => false, // bool
        // if true, unrecognized directives raise errors
        self::OPTION_STRICT => false, // bool
        // if true, use includes to create a single config obj
        self::OPTION_COMBINE => false, // bool
        // if true, runs context analysis on directives
        self::OPTION_CHECK_CTX => true, // bool
        // if true, runs arg count analysis on directives
        self::OPTION_CHECK_ARGS => true, // bool
    ];

    /** @var Lexer */
    private $lexer;

    /** @var Analyzer */
    private $analyzer;

    public function __construct(?Lexer $lexer = null, ?Analyzer $analyzer = null)
    {
        $this->analyzer = $analyzer ?? new Analyzer();
        $this->lexer = $lexer ?? new Lexer();
    }

    /**
     * Parses an nginx config file and returns a nested dict payload.
     *
     * @param string $filename contianing the name of the config file to parse
     * @param array  $options  = [
     *                         'onError' => null,
     *                         'catchErrors' => true,
     *                         'ignore' => [],
     *                         'singleFile' => false,
     *                         'comments' => false,
     *                         'strict' => false,
     *                         'combine' => false,
     *                         'checkCtx' => true,
     *                         'checkArgs' => true,
     *                         ] Parser options
     *
     * @throws NgxParserException
     *
     * @return array a payload that describes the parsed nginx config
     */
    public function parse(
        string $filename,
        array $options = []
    ): array {
        /** @noinspection AdditionOperationOnArraysInspection */
        $options += self::DEFAULT_OPTIONS;
        $this->validateOptions($options);
        [
            self::OPTION_ON_ERROR => $onError,
            self::OPTION_CATCH_ERRORS => $catchErrors,
            self::OPTION_IGNORE => $ignore,
            self::OPTION_SINGLE_FILE => $single,
            self::OPTION_COMMENTS => $comments,
            self::OPTION_STRICT => $strict,
            self::OPTION_COMBINE => $combine,
            self::OPTION_CHECK_CTX => $checkCtx,
            self::OPTION_CHECK_ARGS => $checkArgs,
        ] = $options;

        $configDir = \dirname($filename);
        $payload = [
            'status' => 'ok',
            'errors' => [],
            'config' => [],
        ];

        // start with the main nginx config file/context
        $includes = new \ArrayIterator([
            [$filename, []],
        ]);  // stores (filename, config context) tuples
        $included = [
            $filename => 0,
        ]; // stores {filename: array index} map

        /**
         * Adds representaions of an error to the payload.
         */
        $handleError = static function (array &$parsing, NgxParserException $e) use ($onError, &$payload) {
            $file = $parsing['file'];
            $error = (string) $e;
            $line = $e->getLineNo();

            $parsingError = [
                'error' => $error,
                'line' => $line,
            ];
            $payloadError = [
                'file' => $file,
                'error' => $error,
                'line' => $line,
            ];
            if ($onError !== null) {
                $payloadError['callback'] = $onError($e);
            }

            $parsing['status'] = 'failed';
            $parsing['errors'][] = $parsingError;

            $payload['status'] = 'failed';
            $payload['errors'][] = $payloadError;
        };

        /**
         * Removes parentheses from an "if" directive's arguments.
         */
        $prepareIfArgs = static function (array &$stmt) {
            if (
                !empty($stmt['args'])
                && ($lastKey = array_key_last($stmt['args'])) !== null
                && str_starts_with($stmt['args'][0], '(')
                && str_ends_with($stmt['args'][$lastKey], ')')
            ) {
                $stmt['args'][0] = ltrim(mb_substr($stmt['args'][0], 1));
                $stmt['args'][$lastKey] = rtrim(mb_substr($stmt['args'][$lastKey], 0, -1));
                $start = (int) (empty($stmt['args'][0]));
                $end = \count($stmt['args']) - (int) (empty($stmt['args'][$lastKey]));
                $stmt['args'] = \array_slice($stmt['args'], $start, $end);
            }
        };

        $analyzer = $this->analyzer;

        /**
         * Recursively parses nginx config contexts.
         *
         * @throws NgxParserException
         */
        $parse = static function (array &$parsing, \Iterator $tokens, array $ctx = [], bool $consume = false) use ($analyzer, &$includes, &$included, $filename, $configDir, $single, $handleError, &$parse, $combine, $comments, $prepareIfArgs, $ignore, $strict, $checkCtx, $checkArgs, $catchErrors) {
            $fname = $parsing['file'];
            $parsed = [];

            // parse recursively by pulling from a flat stream of tokens
            foreach (new \NoRewindIterator($tokens) as [$token, $lineNo, $quoted]) {
                $commentsInArgs = [];

                // we are parsing a block, so break if it's closing
                if ($token === '}' && !$quoted) {
                    break;
                }

                // if we are consuming, then just continue until end of context
                if ($consume) {
                    // if we find a block inside this context, consume it too
                    if ($token === '{' && !$quoted) {
                        $tokens->next();
                        $parse($parsing, $tokens, [], true);
                    }

                    continue;
                }

                // the first token should always(?) be an nginx directive
                $directive = $token;

                if ($combine) {
                    $stmt = [
                        'file' => $fname,
                        'directive' => $directive,
                        'line' => $lineNo,
                        'args' => [],
                    ];
                } else {
                    $stmt = [
                        'directive' => $directive,
                        'line' => $lineNo,
                        'args' => [],
                    ];
                }

                // if token is comment
                if (!$quoted && str_starts_with($directive, '#')) {
                    if ($comments) {
                        $stmt['directive'] = '#';
                        $stmt['comment'] = mb_substr($token, 1);
                        $parsed[] = $stmt;
                    }

                    continue;
                }

                // parse arguments by reading tokens
                $tokens->next();
                [$token, , $quoted] = $tokens->current(); // disregard line numbers of args
                $chars = ['{', ';', '}'];
                while (!\in_array($token, $chars, true) || $quoted) {
                    if (!$quoted && str_starts_with($token, '#')) {
                        $commentsInArgs[] = mb_substr($token, 1);
                    } else {
                        $stmt['args'][] = $token;
                    }

                    $tokens->next();
                    [$token, , $quoted] = $tokens->current();
                }

                // consume the directive if it is ignored and move on
                if (\in_array($stmt['directive'], $ignore, true)) {
                    // if this directive was a block consume it too
                    if ($token === '{' && !$quoted) {
                        $tokens->next();
                        $parse($parsing, $tokens, [], true);
                    }

                    continue;
                }

                // prepare arguments
                if ($stmt['directive'] === 'if') {
                    $prepareIfArgs($stmt);
                }

                try {
                    // raise errors if this statement is invalid
                    $analyzer->analyze(
                        $fname,
                        $stmt,
                        $token,
                        [
                            Analyzer::OPTION_CTX => $ctx,
                            Analyzer::OPTION_STRICT => $strict,
                            Analyzer::OPTION_CHECK_CTX => $checkCtx,
                            Analyzer::OPTION_CHECK_ARGS => $checkArgs,
                        ]
                    );
                } catch (NgxParserDirectiveException $e) {
                    if ($catchErrors) {
                        $handleError($parsing, $e);

                        // if it was a block but shouldn't have been then consume
                        if (str_ends_with($e->getMessage(), ' is not terminated by ";"')) {
                            if ($token !== '}' && !$quoted) {
                                $tokens->next();
                                $parse($parsing, $tokens, [], true);
                            } else {
                                break;
                            }
                        }

                        // keep on parsing
                        continue;
                    }

                    throw $e;
                }

                // add "includes" to the payload if this is an include statement
                if (!$single && $stmt['directive'] === 'include') {
                    $pattern = $stmt['args'][0];
                    if (!FileUtil::isAbsolute($stmt['args'][0])) {
                        $pattern = $configDir . \DIRECTORY_SEPARATOR . $stmt['args'][0];
                    }

                    $stmt['includes'] = [];

                    // get names of all included files
                    if (FileUtil::hasGlobMagick($pattern)) {
                        $fnames = glob($pattern);
                    } else {
                        // if the file pattern was explicit, nginx will check
                        // that the included file can be opened and read
                        if (is_file($pattern) && is_readable($pattern)) {
                            $fnames = [$pattern];
                        } else {
                            $e = new NgxParserIOException(sprintf("No such file or directory: '%s'", $pattern), $filename, $lineNo);
                            $fnames = [];
                            if ($catchErrors) {
                                $handleError($parsing, $e);
                            } else {
                                throw $e;
                            }
                        }
                    }

                    foreach ($fnames as $fname) {
                        // the included set keeps files from being parsed twice
                        if (!isset($included[$fname])) {
                            $included[$fname] = \count($includes);
                            $includes[] = [$fname, $ctx];
                        }
                        $index = $included[$fname];
                        $stmt['includes'][] = $index;
                    }
                }

                // if this statement terminated with '{' then it is a block
                if ($token === '{' && !$quoted) {
                    $inner = $analyzer->enterBlockCtx($stmt, $ctx);  // get context for block
                    $tokens->next();
                    $stmt['block'] = $parse($parsing, $tokens, $inner);
                }

                $parsed[] = $stmt;

                // add all comments found inside args after stmt is added
                foreach ($commentsInArgs as $comment) {
                    $commentStmt = [
                        'directive' => '#',
                        'line' => $stmt['line'],
                        'args' => [],
                        'comment' => $comment,
                    ];
                    $parsed[] = $commentStmt;
                }
            }

            return $parsed;
        };

        // the includes list grows as "include" directives are found in _parse
        foreach ($includes as [$fname, $ctx]) {
            $tokens = $this->lexer->lex($fname);
            $parsing = [
                'file' => $fname,
                'status' => 'ok',
                'errors' => [],
                'parsed' => [],
            ];

            try {
                $parsing['parsed'] = $parse($parsing, $tokens, $ctx);
            } catch (NgxParserException $e) {
                $handleError($parsing, $e);
            }

            $payload['config'][] = $parsing;
        }

        if ($combine) {
            return $this->combineParsedConfigs($payload);
        }

        return $payload;
    }

    /**
     * Combines config files into one by using include directives.
     *
     * @param array $oldPayload payload that's normally returned by parse()
     *
     * @return array the new combined payload
     */
    private function combineParsedConfigs(array $oldPayload): array
    {
        $oldConfigs = $oldPayload['config'];

        $performIncludes = static function (iterable $block) use (&$performIncludes, $oldConfigs) {
            foreach ($block as $stmt) {
                if (isset($stmt['block'])) {
                    $stmt['block'] = iterator_to_array($performIncludes($stmt['block']));
                }

                if (isset($stmt['includes'])) {
                    foreach ($stmt['includes'] as $index) {
                        $config = $oldConfigs[$index]['parsed'];

                        foreach ($performIncludes($config) as $_stmt) {
                            yield $_stmt;
                        }
                    }
                } else {
                    yield $stmt;  // do not yield include stmt itself
                }
            }
        };

        $combinedConfig = [
            'file' => $oldConfigs[0]['file'],
            'status' => 'ok',
            'errors' => [],
            'parsed' => [],
        ];

        foreach ($oldConfigs as $config) {
            $combinedConfig['errors'] = array_merge($combinedConfig['errors'], $config['errors'] ?? []);
            if (($config['status'] ?? 'ok') === 'failed') {
                $combinedConfig['status'] = 'failed';
            }
        }

        $firstConfig = $oldConfigs[0]['parsed'];
        $combinedConfig['parsed'] = iterator_to_array($performIncludes($firstConfig));

        return [
            'status' => $oldPayload['status'] ?? 'ok',
            'errors' => $oldPayload['errors'] ?? [],
            'config' => [$combinedConfig],
        ];
    }

    private function validateOptions(array $options): void
    {
        if ($options[self::OPTION_ON_ERROR] !== null && !\is_callable(self::OPTION_ON_ERROR)) {
            throw new \InvalidArgumentException(sprintf('Parser option %s must be null or callable.', self::OPTION_ON_ERROR));
        }

        if (!\is_array($options[self::OPTION_IGNORE])) {
            throw new \InvalidArgumentException(sprintf('Parser option %s must be array.', self::OPTION_IGNORE));
        }

        foreach ([
            self::OPTION_CATCH_ERRORS,
            self::OPTION_SINGLE_FILE,
            self::OPTION_COMMENTS,
            self::OPTION_COMBINE,
            self::OPTION_STRICT,
            self::OPTION_CHECK_CTX,
            self::OPTION_CHECK_ARGS,
        ] as $boolOption) {
            if (!\is_bool($options[$boolOption])) {
                throw new \InvalidArgumentException(sprintf('Parser option %s must be boolean.', $boolOption));
            }
        }
    }
}
