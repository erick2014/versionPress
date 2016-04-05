<?php

namespace VersionPress\Utils;

class IniSerializer {

    private static $sanitizedChars = array(
        "[" => "<<<lbrac>>>",
        "]" => "<<<rbrac>>>",
        "\"" => "<<<dblquot>>>",
        "'" => "<<<quot>>>",
        ";" => "<<<semicol>>>",
        "$" => "<<<string>>>",
        "&" => "<<<amp>>>",
        "~" => "<<<tilde>>>",
        "^" => "<<<power>>>",
        "!" => "<<<exclmark>>>",
        "(" => "<<<lparent>>>",
        ")" => "<<<rparent>>>",
        "{" => "<<<lcurly>>>",
        "}" => "<<<rcurly>>>",
        "|" => "<<<pipe>>>",
        "\t" => "<<<tab>>>",
        "=" => "<<<eq>>>",
    );

    public static function serialize($data) {
        $output = array();
        foreach ($data as $sectionName => $section) {
            if (!is_array($section)) {
                throw new \Exception("INI serializer only supports sectioned data");
            } else if (empty($section)) {
                throw new \Exception("Empty sections are not supported");
            }
            $output = array_merge($output, self::serializeSection($sectionName, $section));
        }
        return self::outputToString($output);
    }

    private static function serializeSection($sectionName, $data) {
        $output = array();
        $output[] = "[$sectionName]";
        $output = array_merge($output, self::serializeData($data));

        if (end($output) !== "") {
            $output[] = "";
        }

        return $output;
    }

    private static function serializeData($data) {
        $output = array();
        foreach ($data as $key => $value) {
            if ($key == '') continue;
            if (is_array($value)) {
                foreach ($value as $arrayKey => $arrayValue) {
                    $output[] = self::serializeKeyValuePair($key . "[$arrayKey]", $arrayValue);
                }

            } else {
                $output[] = self::serializeKeyValuePair($key, $value);
            }
        }
        return $output;
    }

    private static function escapeString($str) {
        $str = str_replace('"', '\"', $str);
        return $str;
    }

    private static function unescapeString($str) {
        $str = str_replace('\"', '"', $str);
        return $str;
    }

    public static function deserialize($string) {
        $string = self::eolWorkaround_addPlaceholders($string);
        $string = self::sanitizeSectionsAndKeys_addPlaceholders($string);
        $deserialized = parse_ini_string($string, true, INI_SCANNER_RAW);
        $deserialized = self::restoreTypesOfValues($deserialized);
        $deserialized = self::sanitizeSectionsAndKeys_removePlaceholders($deserialized);
        $deserialized = self::eolWorkaround_removePlaceholders($deserialized);
        return $deserialized;
    }

    private static function eolWorkaround_addPlaceholders($iniString) {

        $stringValueRegEx = "/[ =]\"(.*)(?<!\\\\)\"/sU";

        $iniString = preg_replace_callback($stringValueRegEx, array('self', 'replace_eol_callback'), $iniString);

        return $iniString;
    }

    private static function replace_eol_callback($matches) {
        return self::getReplacedEolString($matches[0], "charsToPlaceholders");
    }

    private static function eolWorkaround_removePlaceholders($deserializedArray) {

        foreach ($deserializedArray as $key => $value) {
            if (is_array($value)) {
                $deserializedArray[$key] = self::eolWorkaround_removePlaceholders($value);
            } else if (is_string($value)) {
                $deserializedArray[$key] = self::getReplacedEolString($value, "placeholdersToChars");
            }
        }

        return $deserializedArray;

    }

    private static function getReplacedEolString($str, $direction) {

        $replacement = array(
            "\n" => "<<<[EOL-LF]>>>",
            "\r" => "<<<[EOL-CR]>>>",
        );

        $from = ($direction == "charsToPlaceholders") ? array_keys($replacement) : array_values($replacement);
        $to = ($direction == "charsToPlaceholders") ? array_values($replacement) : array_keys($replacement);

        return str_replace($from, $to, $str);

    }

    private static function outputToString($output) {
        return implode("\r\n", $output);
    }

    private static function serializeKeyValuePair($key, $value) {
        return $key . " = " . (is_numeric($value) ? $value : '"' . self::escapeString($value) . '"');
    }

    private static function sanitizeSectionsAndKeys_addPlaceholders($string) {
        $sanitizedChars = self::$sanitizedChars;
        
        
        $string = preg_replace_callback("/^\\[(.*)\\]/m", function ($match) use ($sanitizedChars) {
            $sectionWithPlaceholders = strtr($match[1], $sanitizedChars);
            return "[$sectionWithPlaceholders]";
        }, $string);

        $string = preg_replace_callback("/^(.*?)(\\[[^\\]]*\\])? = /m", function ($match) use ($sanitizedChars) {
            $keyWithPlaceholders = strtr($match[1], $sanitizedChars);
            return $keyWithPlaceholders . (isset($match[2]) ? $match[2] : "") . " = ";
        }, $string);

        return $string;
    }

    private static function sanitizeSectionsAndKeys_removePlaceholders($deserialized) {
        $result = array();
        foreach ($deserialized as $key => $value) {
            $key = strtr($key, array_flip(self::$sanitizedChars));
            if (is_array($value)) {
                $result[$key] = self::sanitizeSectionsAndKeys_removePlaceholders($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private static function restoreTypesOfValues($deserialized) {
        $result = array();
        foreach ($deserialized as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::restoreTypesOfValues($value);
            } else if (is_numeric($value)) {
                $result[$key] = $value + 0;
            } else {
                $result[$key] = self::unescapeString($value);
            }
        }
        return $result;
    }
}
