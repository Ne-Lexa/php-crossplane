<?php

declare(strict_types=1);

namespace Nelexa\NginxParser;

use Nelexa\NginxParser\Exception\NgxParserDirectiveArgumentsException;
use Nelexa\NginxParser\Exception\NgxParserDirectiveContextException;
use Nelexa\NginxParser\Exception\NgxParserDirectiveUnknownException;

class Analyzer
{
    // bit masks for different directive argument styles
    public const NGX_CONF_NOARGS = 0x00000001; // 0 args

    public const NGX_CONF_TAKE1 = 0x00000002; // 1 args

    public const NGX_CONF_TAKE2 = 0x00000004; // 2 args

    public const NGX_CONF_TAKE3 = 0x00000008; // 3 args

    public const NGX_CONF_TAKE4 = 0x00000010; // 4 args

    public const NGX_CONF_TAKE5 = 0x00000020; // 5 args

    public const NGX_CONF_TAKE6 = 0x00000040; // 6 args

    public const NGX_CONF_TAKE7 = 0x00000080; // 7 args

    public const NGX_CONF_BLOCK = 0x00000100; // followed by block

    public const NGX_CONF_FLAG = 0x00000200; // 'on' or 'off'

    public const NGX_CONF_ANY = 0x00000400; // >=0 args

    public const NGX_CONF_1MORE = 0x00000800; // >=1 args

    public const NGX_CONF_2MORE = 0x00001000; // >=2 args

    // some helpful argument style aliases
    public const NGX_CONF_TAKE12 = (self::NGX_CONF_TAKE1 | self::NGX_CONF_TAKE2);

    public const NGX_CONF_TAKE13 = (self::NGX_CONF_TAKE1 | self::NGX_CONF_TAKE3);

    public const NGX_CONF_TAKE23 = (self::NGX_CONF_TAKE2 | self::NGX_CONF_TAKE3);

    public const NGX_CONF_TAKE34 = (self::NGX_CONF_TAKE3 | self::NGX_CONF_TAKE4);

    public const NGX_CONF_TAKE123 = (self::NGX_CONF_TAKE12 | self::NGX_CONF_TAKE3);

    public const NGX_CONF_TAKE1234 = (self::NGX_CONF_TAKE123 | self::NGX_CONF_TAKE4);

    // bit masks for different directive locations
    public const NGX_DIRECT_CONF = 0x00010000; // main file (not used)

    public const NGX_MAIN_CONF = 0x00040000; // main context

    public const NGX_EVENT_CONF = 0x00080000; // events

    public const NGX_MAIL_MAIN_CONF = 0x00100000; // mail

    public const NGX_MAIL_SRV_CONF = 0x00200000; // mail > server

    public const NGX_STREAM_MAIN_CONF = 0x00400000; // stream

    public const NGX_STREAM_SRV_CONF = 0x00800000; // stream > server

    public const NGX_STREAM_UPS_CONF = 0x01000000; // stream > upstream

    public const NGX_HTTP_MAIN_CONF = 0x02000000; // http

    public const NGX_HTTP_SRV_CONF = 0x04000000; // http > server

    public const NGX_HTTP_LOC_CONF = 0x08000000; // http > location

    public const NGX_HTTP_UPS_CONF = 0x10000000; // http > upstream

    public const NGX_HTTP_SIF_CONF = 0x20000000; // http > server > if

    public const NGX_HTTP_LIF_CONF = 0x40000000; // http > location > if

    public const NGX_HTTP_LMT_CONF = 0x80000000; // http > location > limit_except

    // helpful directive location alias describing "any" context
    // doesn't include public const NGX_HTTP_SIF_CONF, public const NGX_HTTP_LIF_CONF, or public const NGX_HTTP_LMT_CONF
    public const NGX_ANY_CONF = (
        self::NGX_MAIN_CONF | self::NGX_EVENT_CONF | self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF
        | self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_STREAM_UPS_CONF
        | self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_UPS_CONF
    );

    /** Map for getting bitmasks from certain context tuples */
    public const CONTEXTS = [
        self::NGX_MAIN_CONF => [],
        self::NGX_EVENT_CONF => ['events'],
        self::NGX_MAIL_MAIN_CONF => ['mail'],
        self::NGX_MAIL_SRV_CONF => ['mail', 'server'],
        self::NGX_STREAM_MAIN_CONF => ['stream'],
        self::NGX_STREAM_SRV_CONF => ['stream', 'server'],
        self::NGX_STREAM_UPS_CONF => ['stream', 'upstream'],
        self::NGX_HTTP_MAIN_CONF => ['http'],
        self::NGX_HTTP_SRV_CONF => ['http', 'server'],
        self::NGX_HTTP_LOC_CONF => ['http', 'location'],
        self::NGX_HTTP_UPS_CONF => ['http', 'upstream'],
        self::NGX_HTTP_SIF_CONF => ['http', 'server', 'if'],
        self::NGX_HTTP_LIF_CONF => ['http', 'location', 'if'],
        self::NGX_HTTP_LMT_CONF => ['http', 'location', 'limit_except'],
    ];

    public const OPTION_CTX = 'ctx';

    public const OPTION_STRICT = 'strict';

    public const OPTION_CHECK_CTX = 'checkCtx';

    public const OPTION_CHECK_ARGS = 'checkArgs';

    public const DEFAULT_OPTIONS = [
        self::OPTION_CTX => [],
        self::OPTION_STRICT => false,
        self::OPTION_CHECK_CTX => true,
        self::OPTION_CHECK_ARGS => true,
    ];

    /**
     * DIRECTIVES.
     *
     * This dict maps directives to lists of bit masks that define their behavior.
     *
     * Each bit mask describes these behaviors:
     * - how many arguments the directive can take
     * - whether or not it is a block directive
     * - whether this is a flag (takes one argument that's either "on" or "off")
     * - which contexts it's allowed to be in
     *
     * Since some directives can have different behaviors in different contexts, we
     * use lists of bit masks, each describing a valid way to use the directive.
     *
     * Definitions for directives that're available in the open source version of
     * nginx were taken directively from the source code. In fact, the variable
     * names for the bit masks defined above were taken from the nginx source code.
     *
     * Definitions for directives that're only available for nginx+ were inferred
     * from the documentation at http://nginx.org/en/docs/.
     *
     * @var array
     */
    private $directives = [
        'absolute_redirect' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'accept_mutex' => [
            self::NGX_EVENT_CONF | self::NGX_CONF_FLAG,
        ],
        'accept_mutex_delay' => [
            self::NGX_EVENT_CONF | self::NGX_CONF_TAKE1,
        ],
        'access_log' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_HTTP_LMT_CONF | self::NGX_CONF_1MORE,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_1MORE,
        ],
        'add_after_body' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'add_before_body' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'add_header' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_TAKE23,
        ],
        'add_trailer' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_TAKE23,
        ],
        'addition_types' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'aio' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'aio_write' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'alias' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'allow' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LMT_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'ancient_browser' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'ancient_browser_value' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'auth_basic' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LMT_CONF | self::NGX_CONF_TAKE1,
        ],
        'auth_basic_user_file' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LMT_CONF | self::NGX_CONF_TAKE1,
        ],
        'auth_http' => [
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'auth_http_header' => [
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE2,
        ],
        'auth_http_pass_client_cert' => [
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'auth_http_timeout' => [
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'auth_request' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'auth_request_set' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE2,
        ],
        'autoindex' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'autoindex_exact_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'autoindex_format' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'autoindex_localtime' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'break' => [
            self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_SIF_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_NOARGS,
        ],
        'charset' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_TAKE1,
        ],
        'charset_map' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_TAKE2,
        ],
        'charset_types' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'chunked_transfer_encoding' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'client_body_buffer_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'client_body_in_file_only' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'client_body_in_single_buffer' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'client_body_temp_path' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1234,
        ],
        'client_body_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'client_header_buffer_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'client_header_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'client_max_body_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'connection_pool_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'create_full_put_path' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'daemon' => [
            self::NGX_MAIN_CONF | self::NGX_DIRECT_CONF | self::NGX_CONF_FLAG,
        ],
        'dav_access' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE123,
        ],
        'dav_methods' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'debug_connection' => [
            self::NGX_EVENT_CONF | self::NGX_CONF_TAKE1,
        ],
        'debug_points' => [
            self::NGX_MAIN_CONF | self::NGX_DIRECT_CONF | self::NGX_CONF_TAKE1,
        ],
        'default_type' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'deny' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LMT_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'directio' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'directio_alignment' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'disable_symlinks' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE12,
        ],
        'empty_gif' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_CONF_NOARGS,
        ],
        'env' => [
            self::NGX_MAIN_CONF | self::NGX_DIRECT_CONF | self::NGX_CONF_TAKE1,
        ],
        'error_log' => [
            self::NGX_MAIN_CONF | self::NGX_CONF_1MORE,
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_1MORE,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_1MORE,
        ],
        'error_page' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_2MORE,
        ],
        'etag' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'events' => [
            self::NGX_MAIN_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_NOARGS,
        ],
        'expires' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_TAKE12,
        ],
        'fastcgi_bind' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE12,
        ],
        'fastcgi_buffer_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_buffering' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'fastcgi_buffers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE2,
        ],
        'fastcgi_busy_buffers_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_cache' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_cache_background_update' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'fastcgi_cache_bypass' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'fastcgi_cache_key' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_cache_lock' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'fastcgi_cache_lock_age' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_cache_lock_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_cache_max_range_offset' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_cache_methods' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'fastcgi_cache_min_uses' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_cache_path' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_2MORE,
        ],
        'fastcgi_cache_revalidate' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'fastcgi_cache_use_stale' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'fastcgi_cache_valid' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'fastcgi_catch_stderr' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_connect_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_force_ranges' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'fastcgi_hide_header' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_ignore_client_abort' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'fastcgi_ignore_headers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'fastcgi_index' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_intercept_errors' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'fastcgi_keep_conn' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'fastcgi_limit_rate' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_max_temp_file_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_next_upstream' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'fastcgi_next_upstream_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_next_upstream_tries' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_no_cache' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'fastcgi_param' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE23,
        ],
        'fastcgi_pass' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_pass_header' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_pass_request_body' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'fastcgi_pass_request_headers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'fastcgi_read_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_request_buffering' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'fastcgi_send_lowat' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_send_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_socket_keepalive' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'fastcgi_split_path_info' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_store' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_store_access' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE123,
        ],
        'fastcgi_temp_file_write_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_temp_path' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1234,
        ],
        'flv' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_CONF_NOARGS,
        ],
        'geo' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_TAKE12,
            self::NGX_STREAM_MAIN_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_TAKE12,
        ],
        'geoip_city' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE12,
            self::NGX_STREAM_MAIN_CONF | self::NGX_CONF_TAKE12,
        ],
        'geoip_country' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE12,
            self::NGX_STREAM_MAIN_CONF | self::NGX_CONF_TAKE12,
        ],
        'geoip_org' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE12,
            self::NGX_STREAM_MAIN_CONF | self::NGX_CONF_TAKE12,
        ],
        'geoip_proxy' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE1,
        ],
        'geoip_proxy_recursive' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_FLAG,
        ],
        'google_perftools_profiles' => [
            self::NGX_MAIN_CONF | self::NGX_DIRECT_CONF | self::NGX_CONF_TAKE1,
        ],
        'grpc_bind' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE12,
        ],
        'grpc_buffer_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'grpc_connect_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'grpc_hide_header' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'grpc_ignore_headers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'grpc_intercept_errors' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'grpc_next_upstream' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'grpc_next_upstream_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'grpc_next_upstream_tries' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'grpc_pass' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_TAKE1,
        ],
        'grpc_pass_header' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'grpc_read_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'grpc_send_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'grpc_set_header' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE2,
        ],
        'grpc_socket_keepalive' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'grpc_ssl_certificate' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'grpc_ssl_certificate_key' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'grpc_ssl_ciphers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'grpc_ssl_crl' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'grpc_ssl_name' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'grpc_ssl_password_file' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'grpc_ssl_protocols' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'grpc_ssl_server_name' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'grpc_ssl_session_reuse' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'grpc_ssl_trusted_certificate' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'grpc_ssl_verify' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'grpc_ssl_verify_depth' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'gunzip' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'gunzip_buffers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE2,
        ],
        'gzip' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_FLAG,
        ],
        'gzip_buffers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE2,
        ],
        'gzip_comp_level' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'gzip_disable' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'gzip_http_version' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'gzip_min_length' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'gzip_proxied' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'gzip_static' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'gzip_types' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'gzip_vary' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'hash' => [
            self::NGX_HTTP_UPS_CONF | self::NGX_CONF_TAKE12,
            self::NGX_STREAM_UPS_CONF | self::NGX_CONF_TAKE12,
        ],
        'http' => [
            self::NGX_MAIN_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_NOARGS,
        ],
        'http2_body_preread_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'http2_chunk_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'http2_idle_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'http2_max_concurrent_pushes' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'http2_max_concurrent_streams' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'http2_max_field_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'http2_max_header_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'http2_max_requests' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'http2_push' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'http2_push_preload' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'http2_recv_buffer_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE1,
        ],
        'http2_recv_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'if' => [
            self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_1MORE,
        ],
        'if_modified_since' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'ignore_invalid_headers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'image_filter' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE123,
        ],
        'image_filter_buffer' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'image_filter_interlace' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'image_filter_jpeg_quality' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'image_filter_sharpen' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'image_filter_transparency' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'image_filter_webp_quality' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'imap_auth' => [
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_1MORE,
        ],
        'imap_capabilities' => [
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_1MORE,
        ],
        'imap_client_buffer' => [
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'include' => [
            self::NGX_ANY_CONF | self::NGX_CONF_TAKE1,
        ],
        'index' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'internal' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_CONF_NOARGS,
        ],
        'ip_hash' => [
            self::NGX_HTTP_UPS_CONF | self::NGX_CONF_NOARGS,
        ],
        'keepalive' => [
            self::NGX_HTTP_UPS_CONF | self::NGX_CONF_TAKE1,
        ],
        'keepalive_disable' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE12,
        ],
        'keepalive_requests' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
            self::NGX_HTTP_UPS_CONF | self::NGX_CONF_TAKE1,
        ],
        'keepalive_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE12,
            self::NGX_HTTP_UPS_CONF | self::NGX_CONF_TAKE1,
        ],
        'large_client_header_buffers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE2,
        ],
        'least_conn' => [
            self::NGX_HTTP_UPS_CONF | self::NGX_CONF_NOARGS,
            self::NGX_STREAM_UPS_CONF | self::NGX_CONF_NOARGS,
        ],
        'limit_conn' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE2,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE2,
        ],
        'limit_conn_dry_run' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'limit_conn_log_level' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'limit_conn_status' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'limit_conn_zone' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE2,
            self::NGX_STREAM_MAIN_CONF | self::NGX_CONF_TAKE2,
        ],
        'limit_except' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_1MORE,
        ],
        'limit_rate' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_TAKE1,
        ],
        'limit_rate_after' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_TAKE1,
        ],
        'limit_req' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE123,
        ],
        'limit_req_dry_run' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'limit_req_log_level' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'limit_req_status' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'limit_req_zone' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE34,
        ],
        'lingering_close' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'lingering_time' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'lingering_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'listen' => [
            self::NGX_HTTP_SRV_CONF | self::NGX_CONF_1MORE,
            self::NGX_MAIL_SRV_CONF | self::NGX_CONF_1MORE,
            self::NGX_STREAM_SRV_CONF | self::NGX_CONF_1MORE,
        ],
        'load_module' => [
            self::NGX_MAIN_CONF | self::NGX_DIRECT_CONF | self::NGX_CONF_TAKE1,
        ],
        'location' => [
            self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_TAKE12,
        ],
        'lock_file' => [
            self::NGX_MAIN_CONF | self::NGX_DIRECT_CONF | self::NGX_CONF_TAKE1,
        ],
        'log_format' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_2MORE,
            self::NGX_STREAM_MAIN_CONF | self::NGX_CONF_2MORE,
        ],
        'log_not_found' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'log_subrequest' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'mail' => [
            self::NGX_MAIN_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_NOARGS,
        ],
        'map' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_TAKE2,
            self::NGX_STREAM_MAIN_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_TAKE2,
        ],
        'map_hash_bucket_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_CONF_TAKE1,
        ],
        'map_hash_max_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_CONF_TAKE1,
        ],
        'master_process' => [
            self::NGX_MAIN_CONF | self::NGX_DIRECT_CONF | self::NGX_CONF_FLAG,
        ],
        'max_ranges' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'memcached_bind' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE12,
        ],
        'memcached_buffer_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'memcached_connect_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'memcached_gzip_flag' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'memcached_next_upstream' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'memcached_next_upstream_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'memcached_next_upstream_tries' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'memcached_pass' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_TAKE1,
        ],
        'memcached_read_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'memcached_send_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'memcached_socket_keepalive' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'merge_slashes' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'min_delete_depth' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'mirror' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'mirror_request_body' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'modern_browser' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE12,
        ],
        'modern_browser_value' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'mp4' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_CONF_NOARGS,
        ],
        'mp4_buffer_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'mp4_max_buffer_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'msie_padding' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'msie_refresh' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'multi_accept' => [
            self::NGX_EVENT_CONF | self::NGX_CONF_FLAG,
        ],
        'open_file_cache' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE12,
        ],
        'open_file_cache_errors' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'open_file_cache_min_uses' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'open_file_cache_valid' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'open_log_file_cache' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1234,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1234,
        ],
        'output_buffers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE2,
        ],
        'override_charset' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_FLAG,
        ],
        'pcre_jit' => [
            self::NGX_MAIN_CONF | self::NGX_DIRECT_CONF | self::NGX_CONF_FLAG,
        ],
        'perl' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LMT_CONF | self::NGX_CONF_TAKE1,
        ],
        'perl_modules' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE1,
        ],
        'perl_require' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE1,
        ],
        'perl_set' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE2,
        ],
        'pid' => [
            self::NGX_MAIN_CONF | self::NGX_DIRECT_CONF | self::NGX_CONF_TAKE1,
        ],
        'pop3_auth' => [
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_1MORE,
        ],
        'pop3_capabilities' => [
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_1MORE,
        ],
        'port_in_redirect' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'postpone_output' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'preread_buffer_size' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'preread_timeout' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'protocol' => [
            self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_bind' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE12,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE12,
        ],
        'proxy_buffer' => [
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_buffer_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_buffering' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'proxy_buffers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE2,
        ],
        'proxy_busy_buffers_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_cache' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_cache_background_update' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'proxy_cache_bypass' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'proxy_cache_convert_head' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'proxy_cache_key' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_cache_lock' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'proxy_cache_lock_age' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_cache_lock_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_cache_max_range_offset' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_cache_methods' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'proxy_cache_min_uses' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_cache_path' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_2MORE,
        ],
        'proxy_cache_revalidate' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'proxy_cache_use_stale' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'proxy_cache_valid' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'proxy_connect_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_cookie_domain' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE12,
        ],
        'proxy_cookie_path' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE12,
        ],
        'proxy_download_rate' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_force_ranges' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'proxy_headers_hash_bucket_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_headers_hash_max_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_hide_header' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_http_version' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_ignore_client_abort' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'proxy_ignore_headers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'proxy_intercept_errors' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'proxy_limit_rate' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_max_temp_file_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_method' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_next_upstream' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'proxy_next_upstream_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_next_upstream_tries' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_no_cache' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'proxy_pass' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_HTTP_LMT_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_pass_error_message' => [
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'proxy_pass_header' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_pass_request_body' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'proxy_pass_request_headers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'proxy_protocol' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'proxy_protocol_timeout' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_read_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_redirect' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE12,
        ],
        'proxy_request_buffering' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'proxy_requests' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_responses' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_send_lowat' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_send_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_set_body' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_set_header' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE2,
        ],
        'proxy_socket_keepalive' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'proxy_ssl' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'proxy_ssl_certificate' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_ssl_certificate_key' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_ssl_ciphers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_ssl_crl' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_ssl_name' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_ssl_password_file' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_ssl_protocols' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_1MORE,
        ],
        'proxy_ssl_server_name' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'proxy_ssl_session_reuse' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'proxy_ssl_trusted_certificate' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_ssl_verify' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'proxy_ssl_verify_depth' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_store' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_store_access' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE123,
        ],
        'proxy_temp_file_write_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_temp_path' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1234,
        ],
        'proxy_timeout' => [
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'proxy_upload_rate' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'random' => [
            self::NGX_HTTP_UPS_CONF | self::NGX_CONF_NOARGS | self::NGX_CONF_TAKE12,
            self::NGX_STREAM_UPS_CONF | self::NGX_CONF_NOARGS | self::NGX_CONF_TAKE12,
        ],
        'random_index' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'read_ahead' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'real_ip_header' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'real_ip_recursive' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'recursive_error_pages' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'referer_hash_bucket_size' => [
            self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'referer_hash_max_size' => [
            self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'request_pool_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'reset_timedout_connection' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'resolver' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
            self::NGX_HTTP_UPS_CONF | self::NGX_CONF_1MORE,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_1MORE,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_1MORE,
        ],
        'resolver_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'return' => [
            self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_SIF_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_TAKE12,
            self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'rewrite' => [
            self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_SIF_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_TAKE23,
        ],
        'rewrite_log' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_SIF_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_FLAG,
        ],
        'root' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_TAKE1,
        ],
        'satisfy' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'scgi_bind' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE12,
        ],
        'scgi_buffer_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'scgi_buffering' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'scgi_buffers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE2,
        ],
        'scgi_busy_buffers_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'scgi_cache' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'scgi_cache_background_update' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'scgi_cache_bypass' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'scgi_cache_key' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'scgi_cache_lock' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'scgi_cache_lock_age' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'scgi_cache_lock_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'scgi_cache_max_range_offset' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'scgi_cache_methods' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'scgi_cache_min_uses' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'scgi_cache_path' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_2MORE,
        ],
        'scgi_cache_revalidate' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'scgi_cache_use_stale' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'scgi_cache_valid' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'scgi_connect_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'scgi_force_ranges' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'scgi_hide_header' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'scgi_ignore_client_abort' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'scgi_ignore_headers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'scgi_intercept_errors' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'scgi_limit_rate' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'scgi_max_temp_file_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'scgi_next_upstream' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'scgi_next_upstream_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'scgi_next_upstream_tries' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'scgi_no_cache' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'scgi_param' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE23,
        ],
        'scgi_pass' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_TAKE1,
        ],
        'scgi_pass_header' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'scgi_pass_request_body' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'scgi_pass_request_headers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'scgi_read_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'scgi_request_buffering' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'scgi_send_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'scgi_socket_keepalive' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'scgi_store' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'scgi_store_access' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE123,
        ],
        'scgi_temp_file_write_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'scgi_temp_path' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1234,
        ],
        'secure_link' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'secure_link_md5' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'secure_link_secret' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'send_lowat' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'send_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'sendfile' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_FLAG,
        ],
        'sendfile_max_chunk' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'server' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_NOARGS,
            self::NGX_HTTP_UPS_CONF | self::NGX_CONF_1MORE,
            self::NGX_MAIL_MAIN_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_NOARGS,
            self::NGX_STREAM_MAIN_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_NOARGS,
            self::NGX_STREAM_UPS_CONF | self::NGX_CONF_1MORE,
        ],
        'server_name' => [
            self::NGX_HTTP_SRV_CONF | self::NGX_CONF_1MORE,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'server_name_in_redirect' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'server_names_hash_bucket_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE1,
        ],
        'server_names_hash_max_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE1,
        ],
        'server_tokens' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'set' => [
            self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_SIF_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_TAKE2,
        ],
        'set_real_ip_from' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'slice' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'smtp_auth' => [
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_1MORE,
        ],
        'smtp_capabilities' => [
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_1MORE,
        ],
        'smtp_client_buffer' => [
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'smtp_greeting_delay' => [
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'source_charset' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_TAKE1,
        ],
        'spdy_chunk_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'spdy_headers_comp' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'split_clients' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_TAKE2,
            self::NGX_STREAM_MAIN_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_TAKE2,
        ],
        'ssi' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_FLAG,
        ],
        'ssi_last_modified' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'ssi_min_file_chunk' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'ssi_silent_errors' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'ssi_types' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'ssi_value_length' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'ssl' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_FLAG,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'ssl_buffer_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'ssl_certificate' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'ssl_certificate_key' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'ssl_ciphers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'ssl_client_certificate' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'ssl_crl' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'ssl_dhparam' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'ssl_early_data' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'ssl_ecdh_curve' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'ssl_engine' => [
            self::NGX_MAIN_CONF | self::NGX_DIRECT_CONF | self::NGX_CONF_TAKE1,
        ],
        'ssl_handshake_timeout' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'ssl_password_file' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'ssl_prefer_server_ciphers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_FLAG,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_FLAG,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'ssl_preread' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'ssl_protocols' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_1MORE,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_1MORE,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_1MORE,
        ],
        'ssl_session_cache' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE12,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE12,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE12,
        ],
        'ssl_session_ticket_key' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'ssl_session_tickets' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_FLAG,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_FLAG,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'ssl_session_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'ssl_stapling' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'ssl_stapling_file' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'ssl_stapling_responder' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'ssl_stapling_verify' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'ssl_trusted_certificate' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'ssl_verify_client' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'ssl_verify_depth' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'starttls' => [
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'stream' => [
            self::NGX_MAIN_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_NOARGS,
        ],
        'stub_status' => [
            self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_NOARGS | self::NGX_CONF_TAKE1,
        ],
        'sub_filter' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE2,
        ],
        'sub_filter_last_modified' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'sub_filter_once' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'sub_filter_types' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'subrequest_output_buffer_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'tcp_nodelay' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'tcp_nopush' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'thread_pool' => [
            self::NGX_MAIN_CONF | self::NGX_DIRECT_CONF | self::NGX_CONF_TAKE23,
        ],
        'timeout' => [
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'timer_resolution' => [
            self::NGX_MAIN_CONF | self::NGX_DIRECT_CONF | self::NGX_CONF_TAKE1,
        ],
        'try_files' => [
            self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_2MORE,
        ],
        'types' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_NOARGS,
        ],
        'types_hash_bucket_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'types_hash_max_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'underscores_in_headers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'uninitialized_variable_warn' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_SIF_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_FLAG,
        ],
        'upstream' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_TAKE1,
        ],
        'use' => [
            self::NGX_EVENT_CONF | self::NGX_CONF_TAKE1,
        ],
        'user' => [
            self::NGX_MAIN_CONF | self::NGX_DIRECT_CONF | self::NGX_CONF_TAKE12,
        ],
        'userid' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'userid_domain' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'userid_expires' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'userid_mark' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'userid_name' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'userid_p3p' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'userid_path' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'userid_service' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_bind' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE12,
        ],
        'uwsgi_buffer_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_buffering' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'uwsgi_buffers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE2,
        ],
        'uwsgi_busy_buffers_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_cache' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_cache_background_update' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_cache_bypass' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'uwsgi_cache_key' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_cache_lock' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'uwsgi_cache_lock_age' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_cache_lock_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_cache_max_range_offset' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_cache_methods' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'uwsgi_cache_min_uses' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_cache_path' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_2MORE,
        ],
        'uwsgi_cache_revalidate' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'uwsgi_cache_use_stale' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'uwsgi_cache_valid' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'uwsgi_connect_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_force_ranges' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'uwsgi_hide_header' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_ignore_client_abort' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'uwsgi_ignore_headers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'uwsgi_intercept_errors' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'uwsgi_limit_rate' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_max_temp_file_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_modifier1' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_modifier2' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_next_upstream' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'uwsgi_next_upstream_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_next_upstream_tries' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_no_cache' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'uwsgi_param' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE23,
        ],
        'uwsgi_pass' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LIF_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_pass_header' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_pass_request_body' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'uwsgi_pass_request_headers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'uwsgi_read_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_request_buffering' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'uwsgi_send_timeout' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_socket_keepalive' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'uwsgi_ssl_certificate' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_ssl_certificate_key' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_ssl_ciphers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_ssl_crl' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_ssl_name' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_ssl_password_file' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_ssl_protocols' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'uwsgi_ssl_server_name' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'uwsgi_ssl_session_reuse' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'uwsgi_ssl_trusted_certificate' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_ssl_verify' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'uwsgi_ssl_verify_depth' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_store' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_store_access' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE123,
        ],
        'uwsgi_temp_file_write_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'uwsgi_temp_path' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1234,
        ],
        'valid_referers' => [
            self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'variables_hash_bucket_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_CONF_TAKE1,
        ],
        'variables_hash_max_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_CONF_TAKE1,
        ],
        'worker_aio_requests' => [
            self::NGX_EVENT_CONF | self::NGX_CONF_TAKE1,
        ],
        'worker_connections' => [
            self::NGX_EVENT_CONF | self::NGX_CONF_TAKE1,
        ],
        'worker_cpu_affinity' => [
            self::NGX_MAIN_CONF | self::NGX_DIRECT_CONF | self::NGX_CONF_1MORE,
        ],
        'worker_priority' => [
            self::NGX_MAIN_CONF | self::NGX_DIRECT_CONF | self::NGX_CONF_TAKE1,
        ],
        'worker_processes' => [
            self::NGX_MAIN_CONF | self::NGX_DIRECT_CONF | self::NGX_CONF_TAKE1,
        ],
        'worker_rlimit_core' => [
            self::NGX_MAIN_CONF | self::NGX_DIRECT_CONF | self::NGX_CONF_TAKE1,
        ],
        'worker_rlimit_nofile' => [
            self::NGX_MAIN_CONF | self::NGX_DIRECT_CONF | self::NGX_CONF_TAKE1,
        ],
        'worker_shutdown_timeout' => [
            self::NGX_MAIN_CONF | self::NGX_DIRECT_CONF | self::NGX_CONF_TAKE1,
        ],
        'working_directory' => [
            self::NGX_MAIN_CONF | self::NGX_DIRECT_CONF | self::NGX_CONF_TAKE1,
        ],
        'xclient' => [
            self::NGX_MAIL_MAIN_CONF | self::NGX_MAIL_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'xml_entities' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'xslt_last_modified' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'xslt_param' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE2,
        ],
        'xslt_string_param' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE2,
        ],
        'xslt_stylesheet' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'xslt_types' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'zone' => [
            self::NGX_HTTP_UPS_CONF | self::NGX_CONF_TAKE12,
            self::NGX_STREAM_UPS_CONF | self::NGX_CONF_TAKE12,
        ],

        // nginx+ directives [definitions inferred from docs]
        'api' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_CONF_NOARGS | self::NGX_CONF_TAKE1,
        ],
        'auth_jwt' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE12,
        ],
        'auth_jwt_claim_set' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_2MORE,
        ],
        'auth_jwt_header_set' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_2MORE,
        ],
        'auth_jwt_key_file' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'auth_jwt_key_request' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'auth_jwt_leeway' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'f4f' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_CONF_NOARGS,
        ],
        'f4f_buffer_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'fastcgi_cache_purge' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'health_check' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_CONF_ANY,
            self::NGX_STREAM_SRV_CONF | self::NGX_CONF_ANY,
        ],
        'health_check_timeout' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'hls' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_CONF_NOARGS,
        ],
        'hls_buffers' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE2,
        ],
        'hls_forward_args' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'hls_fragment' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'hls_mp4_buffer_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'hls_mp4_max_buffer_size' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'js_access' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'js_content' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_HTTP_LMT_CONF | self::NGX_CONF_TAKE1,
        ],
        'js_filter' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'js_include' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_CONF_TAKE1,
        ],
        'js_path' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE1,
        ],
        'js_preread' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'js_set' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE2,
            self::NGX_STREAM_MAIN_CONF | self::NGX_CONF_TAKE2,
        ],
        'keyval' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE3,
            self::NGX_STREAM_MAIN_CONF | self::NGX_CONF_TAKE3,
        ],
        'keyval_zone' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_1MORE,
            self::NGX_STREAM_MAIN_CONF | self::NGX_CONF_1MORE,
        ],
        'least_time' => [
            self::NGX_HTTP_UPS_CONF | self::NGX_CONF_TAKE12,
            self::NGX_STREAM_UPS_CONF | self::NGX_CONF_TAKE12,
        ],
        'limit_zone' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE3,
        ],
        'match' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_MAIN_CONF | self::NGX_CONF_BLOCK | self::NGX_CONF_TAKE1,
        ],
        'memcached_force_ranges' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_FLAG,
        ],
        'mp4_limit_rate' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'mp4_limit_rate_after' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'ntlm' => [
            self::NGX_HTTP_UPS_CONF | self::NGX_CONF_NOARGS,
        ],
        'proxy_cache_purge' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'queue' => [
            self::NGX_HTTP_UPS_CONF | self::NGX_CONF_TAKE12,
        ],
        'scgi_cache_purge' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'session_log' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
        ],
        'session_log_format' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_2MORE,
        ],
        'session_log_zone' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_CONF_TAKE23 | self::NGX_CONF_TAKE4 | self::NGX_CONF_TAKE5 | self::NGX_CONF_TAKE6,
        ],
        'state' => [
            self::NGX_HTTP_UPS_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_UPS_CONF | self::NGX_CONF_TAKE1,
        ],
        'status' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_CONF_NOARGS,
        ],
        'status_format' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE12,
        ],
        'status_zone' => [
            self::NGX_HTTP_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
            self::NGX_HTTP_LOC_CONF | self::NGX_CONF_TAKE1,
            self::NGX_HTTP_LIF_CONF | self::NGX_CONF_TAKE1,
        ],
        'sticky' => [
            self::NGX_HTTP_UPS_CONF | self::NGX_CONF_1MORE,
        ],
        'sticky_cookie_insert' => [
            self::NGX_HTTP_UPS_CONF | self::NGX_CONF_TAKE1234,
        ],
        'upstream_conf' => [
            self::NGX_HTTP_LOC_CONF | self::NGX_CONF_NOARGS,
        ],
        'uwsgi_cache_purge' => [
            self::NGX_HTTP_MAIN_CONF | self::NGX_HTTP_SRV_CONF | self::NGX_HTTP_LOC_CONF | self::NGX_CONF_1MORE,
        ],
        'zone_sync' => [
            self::NGX_STREAM_SRV_CONF | self::NGX_CONF_NOARGS,
        ],
        'zone_sync_buffers' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE2,
        ],
        'zone_sync_connect_retry_interval' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'zone_sync_connect_timeout' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'zone_sync_interval' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'zone_sync_recv_buffer_size' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'zone_sync_server' => [
            self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE12,
        ],
        'zone_sync_ssl' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'zone_sync_ssl_certificate' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'zone_sync_ssl_certificate_key' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'zone_sync_ssl_ciphers' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'zone_sync_ssl_crl' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'zone_sync_ssl_name' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'zone_sync_ssl_password_file' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'zone_sync_ssl_protocols' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_1MORE,
        ],
        'zone_sync_ssl_server_name' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'zone_sync_ssl_trusted_certificate' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'zone_sync_ssl_verify' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_FLAG,
        ],
        'zone_sync_ssl_verify_depth' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
        'zone_sync_timeout' => [
            self::NGX_STREAM_MAIN_CONF | self::NGX_STREAM_SRV_CONF | self::NGX_CONF_TAKE1,
        ],
    ];

    /**
     * @param string $filename
     * @param array  $stmt
     * @param string $term
     * @param array  $options  = [
     *                         'ctx' => [],
     *                         'strict' => false,
     *                         'checkCtx' => true,
     *                         'checkArgs' => true,
     *                         ] Analyzer options
     *
     * @throws NgxParserDirectiveArgumentsException
     * @throws NgxParserDirectiveContextException
     * @throws NgxParserDirectiveUnknownException
     */
    public function analyze(
        string $filename,
        array $stmt,
        string $term,
        array $options = []
    ): void {
        /** @noinspection AdditionOperationOnArraysInspection */
        $options += self::DEFAULT_OPTIONS;
        $this->validateOptions($options);
        [
            self::OPTION_CTX => $ctx,
            self::OPTION_STRICT => $strict,
            self::OPTION_CHECK_CTX => $checkCtx,
            self::OPTION_CHECK_ARGS => $checkArgs,
        ] = $options;

        $directive = $stmt['directive'];
        $line = $stmt['line'];

        // if strict and directive isn't recognized then throw error
        if ($strict && !isset($this->directives[$directive])) {
            $reason = sprintf('unknown directive "%s"', $directive);

            throw new NgxParserDirectiveUnknownException($reason, $filename, $line);
        }

        // if we don't know where this directive is allowed and how
        // many arguments it can take then don't bother analyzing it
        $ctxMask = array_search($ctx, self::CONTEXTS, true);
        if ($ctxMask === false || !isset($this->directives[$directive])) {
            return;
        }

        $args = $stmt['args'] ?? [];
        $nArgs = \count($args);

        $masks = $this->directives[$directive];

        // if this directive can't be used in this context then throw an error
        if ($checkCtx) {
            $masks = array_values(array_filter($masks, static function ($mask) use ($ctxMask) {
                return ($mask & $ctxMask) === $ctxMask;
            }));
            if (empty($masks)) {
                $reason = sprintf('"%s" directive is not allowed here', $directive);

                throw new NgxParserDirectiveContextException($reason, $filename, $line);
            }
        }

        if (!$checkArgs) {
            return;
        }

        $validFlag = static function (string $x) {
            return \in_array(strtolower($x), ['on', 'off'], true);
        };

        $reason = '';

        // do this in reverse because we only throw errors at the end if no masks
        // are valid, and typically the first bit mask is what the parser expects
        foreach (array_reverse($masks) as $mask) {
            // if the directive isn't a block but should be according to the mask
            if (($mask & self::NGX_CONF_BLOCK) && $term !== '{') {
                $reason = 'directive "%s" has no opening "{"';

                continue;
            }

            // if the directive is a block but shouldn't be according to the mask
            if (!($mask & self::NGX_CONF_BLOCK) && $term !== ';') {
                $reason = 'directive "%s" is not terminated by ";"';

                continue;
            }

            // use mask to check the directive's arguments
            if ((($mask >> $nArgs) & 1 && $nArgs <= 7)  // NOARGS to TAKE7
                || ($mask & self::NGX_CONF_FLAG && $nArgs === 1 && $validFlag($args[0]))
                || ($mask & self::NGX_CONF_ANY && $nArgs >= 0)
                || ($mask & self::NGX_CONF_1MORE && $nArgs >= 1)
                || ($mask & self::NGX_CONF_2MORE && $nArgs >= 2)) {
                return;
            }

            if (($mask & self::NGX_CONF_FLAG) && $nArgs === 1 && !$validFlag($args[0])) {
                $reason = sprintf('invalid value "%s" in "%%s" directive, it must be "on" or "off"', $args[0]);
            } else {
                $reason = 'invalid number of arguments in "%s" directive';
            }
        }

        throw new NgxParserDirectiveArgumentsException(sprintf($reason, $directive), $filename, $line);
    }

    public function enterBlockCtx(array $stmt, array $ctx): array
    {
        // don't nest because NGX_HTTP_LOC_CONF just means "location block in http"
        if (!empty($ctx) && $ctx[0] === 'http' && $stmt['directive'] === 'location') {
            return ['http', 'location'];
        }

        // no other block contexts can be nested like location so just append it
        $ctx[] = $stmt['directive'];

        return $ctx;
    }

    public function registerExternalDirectives($directives): void
    {
        foreach ($directives as $directive => $bitmasks) {
            if ($bitmasks) {
                $this->directives[$directive] = $bitmasks;
            }
        }
    }

    private function validateOptions(array $options): void
    {
        if (!\is_array($options[self::OPTION_CTX])) {
            throw new \InvalidArgumentException(sprintf('Parser option %s must be array.', self::OPTION_CTX));
        }

        if (!\is_bool($options[self::OPTION_CHECK_CTX])) {
            throw new \InvalidArgumentException(sprintf('Parser option %s must be boolean.', self::OPTION_CHECK_CTX));
        }

        if (!\is_bool($options[self::OPTION_CHECK_ARGS])) {
            throw new \InvalidArgumentException(sprintf('Parser option %s must be boolean.', self::OPTION_CHECK_ARGS));
        }
    }
}
