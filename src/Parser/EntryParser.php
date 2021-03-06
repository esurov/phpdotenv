<?php

declare(strict_types=1);

namespace Dotenv\Parser;

use Dotenv\Util\Regex;
use Dotenv\Util\Str;
use GrahamCampbell\ResultType\Error;
use GrahamCampbell\ResultType\Result;
use GrahamCampbell\ResultType\Success;
use RuntimeException;

final class EntryParser
{
    private const INITIAL_STATE = 0;
    private const UNQUOTED_STATE = 1;
    private const SINGLE_QUOTED_STATE = 2;
    private const DOUBLE_QUOTED_STATE = 3;
    private const ESCAPE_SEQUENCE_STATE = 4;
    private const WHITESPACE_STATE = 5;
    private const COMMENT_STATE = 6;

    /**
     * This class is a singleton.
     *
     * @codeCoverageIgnore
     *
     * @return void
     */
    private function __construct()
    {
        //
    }

    /**
     * Parse a raw entry into a proper entry.
     *
     * That is, turn a raw environment variable entry into a name and possibly
     * a value. We wrap the answer in a result type.
     *
     * @param string $entry
     *
     * @return \GrahamCampbell\ResultType\Result<\Dotenv\Parser\Entry,string>
     */
    public static function parse(string $entry)
    {
        return self::splitStringIntoParts($entry)->flatMap(function (array $parts) {
            [$name, $value] = $parts;

            return self::parseName($name)->flatMap(function (string $name) use ($value) {
                $parsedValue = $value === null ? Success::create(null) : self::parseValue($value);

                return $parsedValue->map(function (?Value $value) use ($name) {
                    return new Entry($name, $value);
                });
            });
        });
    }

    /**
     * Split the compound string into parts.
     *
     * @param string $line
     *
     * @return \GrahamCampbell\ResultType\Result<array{string,string|null},string>
     */
    private static function splitStringIntoParts(string $line)
    {
        /** @var array{string,string|null} */
        $result = Str::pos($line, '=')->map(function () use ($line) {
            return \array_map('trim', \explode('=', $line, 2));
        })->getOrElse([$line, null]);

        if ($result[0] === '') {
            return Error::create(self::getErrorMessage('an unexpected equals', $line));
        }

        /** @var \GrahamCampbell\ResultType\Result<array{string,string|null},string> */
        return Success::create($result);
    }

    /**
     * Parse the given variable name.
     *
     * That is, strip the optional quotes and leading "export" from the
     * variable name. We wrap the answer in a result type.
     *
     * @param string $name
     *
     * @return \GrahamCampbell\ResultType\Result<string,string>
     */
    private static function parseName(string $name)
    {
        if (Str::len($name) > 8 && Str::substr($name, 0, 6) === 'export' && ctype_space(Str::substr($name, 6, 1))) {
            $name = ltrim(Str::substr($name, 6));
        }

        if (self::isQuotedName($name)) {
            $name = Str::substr($name, 1, -1);
        }

        if (!self::isValidName($name)) {
            return Error::create(self::getErrorMessage('an invalid name', $name));
        }

        return Success::create($name);
    }

    /**
     * Is the given variable name quoted?
     *
     * @param string $name
     *
     * @return bool
     */
    private static function isQuotedName(string $name)
    {
        if (Str::len($name) < 3) {
            return false;
        }

        $first = Str::substr($name, 0, 1);
        $last = Str::substr($name, -1, 1);

        return ($first === '"' && $last === '"') || ($first === '\'' && $last === '\'');
    }

    /**
     * Is the given variable name valid?
     *
     * @param string $name
     *
     * @return bool
     */
    private static function isValidName(string $name)
    {
        return Regex::match('~\A[a-zA-Z0-9_.]+\z~', $name)->success()->getOrElse(0) === 1;
    }

    /**
     * Parse the given variable value.
     *
     * This has the effect of stripping quotes and comments, dealing with
     * special characters, and locating nested variables, but not resolving
     * them. Formally, we run a finite state automaton with an output tape: a
     * transducer. We wrap the answer in a result type.
     *
     * @param string $value
     *
     * @return \GrahamCampbell\ResultType\Result<\Dotenv\Parser\Value,string>
     */
    private static function parseValue(string $value)
    {
        if (\trim($value) === '') {
            return Success::create(Value::blank());
        }

        return \array_reduce(Lexer::lex($value), function (Result $data, string $token) use ($value) {
            return $data->flatMap(function (array $data) use ($token, $value) {
                return self::processChar($data[1], $token)->mapError(function (string $err) use ($value) {
                    return self::getErrorMessage($err, $value);
                })->map(function (array $val) use ($data) {
                    return [$data[0]->append($val[0], $val[1]), $val[2]];
                });
            });
        }, Success::create([Value::blank(), self::INITIAL_STATE]))->map(function (array $data) {
            return $data[0];
        });
    }

    /**
     * Process the given character.
     *
     * @param int    $state
     * @param string $token
     *
     * @return \GrahamCampbell\ResultType\Result<array{string,bool,int},string>
     */
    private static function processChar(int $state, string $token)
    {
        switch ($state) {
            case self::INITIAL_STATE:
                if ($token === '\'') {
                    return Success::create(['', false, self::SINGLE_QUOTED_STATE]);
                } elseif ($token === '"') {
                    return Success::create(['', false, self::DOUBLE_QUOTED_STATE]);
                } elseif ($token === '#') {
                    return Success::create(['', false, self::COMMENT_STATE]);
                } elseif ($token === '$') {
                    return Success::create([$token, true, self::UNQUOTED_STATE]);
                } else {
                    return Success::create([$token, false, self::UNQUOTED_STATE]);
                }
            case self::UNQUOTED_STATE:
                if ($token === '#') {
                    return Success::create(['', false, self::COMMENT_STATE]);
                } elseif (\ctype_space($token)) {
                    return Success::create(['', false, self::WHITESPACE_STATE]);
                } elseif ($token === '$') {
                    return Success::create([$token, true, self::UNQUOTED_STATE]);
                } else {
                    return Success::create([$token, false, self::UNQUOTED_STATE]);
                }
            case self::SINGLE_QUOTED_STATE:
                if ($token === '\'') {
                    return Success::create(['', false, self::WHITESPACE_STATE]);
                } else {
                    return Success::create([$token, false, self::SINGLE_QUOTED_STATE]);
                }
            case self::DOUBLE_QUOTED_STATE:
                if ($token === '"') {
                    return Success::create(['', false, self::WHITESPACE_STATE]);
                } elseif ($token === '\\') {
                    return Success::create(['', false, self::ESCAPE_SEQUENCE_STATE]);
                } elseif ($token === '$') {
                    return Success::create([$token, true, self::DOUBLE_QUOTED_STATE]);
                } else {
                    return Success::create([$token, false, self::DOUBLE_QUOTED_STATE]);
                }
            case self::ESCAPE_SEQUENCE_STATE:
                if ($token === '"' || $token === '\\') {
                    return Success::create([$token, false, self::DOUBLE_QUOTED_STATE]);
                } elseif ($token === '$') {
                    return Success::create([$token, false, self::DOUBLE_QUOTED_STATE]);
                } else {
                    $first = Str::substr($token, 0, 1);
                    if (\in_array($first, ['f', 'n', 'r', 't', 'v'], true)) {
                        return Success::create([\stripcslashes('\\'.$first).Str::substr($token, 1), false, self::DOUBLE_QUOTED_STATE]);
                    } else {
                        return Error::create('an unexpected escape sequence');
                    }
                }
            case self::WHITESPACE_STATE:
                if ($token === '#') {
                    return Success::create(['', false, self::COMMENT_STATE]);
                } elseif (!\ctype_space($token)) {
                    return Error::create('unexpected whitespace');
                } else {
                    return Success::create(['', false, self::WHITESPACE_STATE]);
                }
            case self::COMMENT_STATE:
                return Success::create(['', false, self::COMMENT_STATE]);
            default:
                throw new RuntimeException('Parser entered invalid state.');
        }
    }

    /**
     * Generate a friendly error message.
     *
     * @param string $cause
     * @param string $subject
     *
     * @return string
     */
    private static function getErrorMessage(string $cause, string $subject)
    {
        return \sprintf(
            'Encountered %s at [%s].',
            $cause,
            \strtok($subject, "\n")
        );
    }
}
