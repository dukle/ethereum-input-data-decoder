<?php

namespace TWOamigos;

use Exception;
use kornrunner\Keccak;

//const utils = require('ethereumjs-util')
//const BN = require('bn.js')

class EthereumAbi
{

    public static function eventID($name, $types)
    {
        // FIXME: use node.js util.format?

        $types = implode(',', array_map('self::elementaryName', $types));

        $sig = $name . '(' . $types . ')';

//        $buffer = unpack('H*', $sig);
//        $buffer = array_pop($buffer);

//        return Keccak::hash($buffer, 256);
        return Keccak::hash($sig, 256);
    }

    public static function methodID($name, $types)
    {
//        return substr(self::eventID($name, $types), 0, 4);
        return substr(self::eventID($name, $types), 0, 8);
    }

    public static function rawDecode($types, $data, $dataBin)
    {
        $ret = [];
//      $data = new Buffer(data)
        $offset = 0;
        foreach ($types as $t) {
            $type = self::elementaryName($t);
            $parsed = self::parseType($type, $data, $offset);
            $decoded = self::decodeSingle($parsed, $data, $dataBin, $offset);
            $offset += $parsed->memoryUsage;
            $ret[] = $decoded;
        }

        return $ret;
    }





// Convert from short to canonical names
// FIXME: optimise or make this nicer?
    public static function elementaryName($name)
    {
        if (strpos($name, 'int[') === 0) {
            return 'int256' . substr($name, 3);
        } else if ($name === 'int') {
            return 'int256';
        } else if (strpos($name, 'uint[') === 0) {
            return 'uint256' . substr($name, 4);
        } else if ($name === 'uint') {
            return 'uint256';
        } else if (strpos($name, 'fixed[') === 0) {
            return 'fixed128x128' . substr($name, 5);
        } else if ($name === 'fixed') {
            return 'fixed128x128';
        } else if (strpos($name, 'ufixed[') === 0) {
            return 'ufixed128x128' . substr($name, 6);
        } else if ($name === 'ufixed') {
            return 'ufixed128x128';
        }
        return $name;
    }


    // Parse N from type<N>
    public static function parseTypeN($type)
    {
        preg_match('/^\D+(\d+)$/', $type, $matches);
        return (int)$matches[1];
    }

    // Parse N,M from type<N>x<M>
    public static function parseTypeNxM($type)
    {
        preg_match('/^\D+(\d+)x(\d+)$/', $type, $matches);
        return [(int)$matches[1], (int)$matches[2]];
    }

    // Parse N in type[<N>] where "type" can itself be an array type.
    public static function parseTypeArray($type)
    {
        preg_match('/(.*)\[(.*?)\]$/', $type, $matches);
        if ($matches) {
            return $matches[2] === '' ? 'dynamic' : int($matches[2]);
        }
        return null;
    }


    // Decodes a single item (can be dynamic array)
    // @returns: array
    // FIXME: this method will need a lot of attention at checking limits and validation
    public static function decodeSingle($parsedType, $data, $dataBin, $offset)
    {
        if (is_string($parsedType)) {
            $parsedType = self::parseType($parsedType);
        }

        $size = null;
        $num = null;
        $ret = null;
        $i = null;

        if ($parsedType->name === 'address') {
            return self::decodeSingle($parsedType->rawType, $data, $dataBin, $offset); //.toArrayLike(Buffer, 'be', 20).toString('hex')
        } else if ($parsedType->name === 'bool') {
            return (string)self::decodeSingle($parsedType->rawType, $data, $dataBin, $offset) . toString() === '1';
        } else if ($parsedType->name === 'string') {
            $bytes = self::decodeSingle($parsedType->rawType, $data, $dataBin, $offset);
            return $bytes; //new Buffer(bytes, 'utf8').toString()
        } else if ($parsedType->isArray) {
            // this part handles fixed-length arrays ([2]) and variable length ([]) arrays
            // NOTE: we catch here all calls to arrays, that simplifies the rest
            $ret = [];
            $size = $parsedType->size;

            if ($parsedType->size === 'dynamic') {
                $offset = (int)self::decodeSingle('uint256', $data, $dataBin, $offset);
                $size = self::decodeSingle('uint256', $data, $dataBin, $offset);
                $offset = $offset + 32;
            }
            for ($i = 0; $i < $size; $i++) {
                $decoded = self::decodeSingle($parsedType->subArray, $data, $dataBin, $offset);
                $ret[] = $decoded;
                $offset += $parsedType->subArray->memoryUsage;
            }
            return $ret;
        } else if ($parsedType->name === 'bytes') {
            $offset = (int)self::decodeSingle('uint256', $data, $dataBin, $offset);
            $size = (int)self::decodeSingle('uint256', $data, $dataBin, $offset);
            return bin2hex(substr($dataBin, $offset + 32, ($offset + 32 + $size) - ($offset + 32)));
        } else if (strpos($parsedType->name, 'bytes') === 0) {
            return bin2hex(substr($dataBin, $offset, ($offset + $parsedType->size) - $offset));
        } else if (strpos($parsedType->name, 'uint') === 0) {
            $num = bin2hex(substr($dataBin, $offset, $offset + 32 - $offset));

            if (strlen(bin2hex($num)) / 2 > $parsedType->size) {
                throw new Exception('Decoded int exceeds width: ' . $parsedType->size . ' vs ' . strlen(bin2hex($num)) / 2);
            }

//    if (num.bitLength() > parsedType.size) {
//        throw new Error('Decoded int exceeds width: ' + parsedType.size + ' vs ' + num.bitLength())
//    }
            return $num;
        } else if (strpos($parsedType->name, 'int') === 0) {
            $num = bin2hex(substr($dataBin, $offset, $offset + 32 - $offset));

//          new BN(data.slice(offset, offset + 32), 16, 'be').fromTwos(256)

            if (strlen(bin2hex($num)) / 2 > $parsedType->size) {
                throw new Exception('Decoded int exceeds width: ' . $parsedType->size . ' vs ' . strlen(bin2hex($num)) / 2);
            }

            return $num;
        } else if (strpos($parsedType->name, 'ufixed') === 0) {
            $size = pow(2, $parsedType->size[1]);
            $num = self::decodeSingle('uint256', $data, $dataBin, $offset);
            if (!($num % $size === 0)) {
                throw new Exception('Decimals not supported yet');
            }
            return $num / $size;
        } else if (strpos($parsedType->name, 'fixed') === 0) {
            $size = pow(2, $parsedType->size[1]);
            $num = self::decodeSingle('int256', $data, $dataBin, $offset);
            if (!($num % $size === 0)) {
                throw new Exception('Decimals not supported yet');
            }
            return $num / $size;
        }
        throw new Exception('Unsupported or invalid type: ' . $parsedType->name);
    }


    // Parse the given type
    // @returns: {} containing the type itself, memory usage and (including size and subArray if applicable)
    public static function parseType($type)
    {
        $size = null;
        $ret = null;
        if (is_array($type)) {
            $size = self::parseTypeArray($type);

            $subArray = substr($type, 0, strrpos($type, '['));
            $subArray = self::parseType($subArray);

            $ret = new \stdClass();
            $ret->isArray = true;
            $ret->name = $type;
            $ret->size = $size;
            $ret->memoryUsage = ($size === 'dynamic') ? 32 : $subArray->memoryUsage * $size;
            $ret->subArray = $subArray;

            return $ret;
        } else {
            $rawType = null;
            switch ($type) {
                case 'address':
                    $rawType = 'uint160';
                    break;
                case 'bool':
                    $rawType = 'uint8';
                    break;
                case 'string':
                    $rawType = 'bytes';
                    break;
            }
            $ret = new \stdClass();
            $ret->isArray = false;
            $ret->rawType = $rawType;
            $ret->name = $type;
            $ret->memoryUsage = 32;

            if (strpos($type, 'bytes') === 0 && $type !== 'bytes' || strpos($type, 'uint') === 0 || strpos($type, 'int') === 0) {
                $ret->size = self::parseTypeN($type);
            } else if (strpos($type, 'ufixed') === 0 || strpos($type, 'fixed') === 0) {
                $ret->size = self::parseTypeNxM($type);
            }

            if (strpos($type, 'bytes') === 0 && $type !== 'bytes' && ($ret->size < 1 || $ret->size > 32)) {
                throw new Exception('Invalid bytes<N> width: ' . $ret->size);
            }
            if ((strpos($type, 'uint') === 0 || strpos($type, 'int') === 0) &&
                ($ret->size % 8 || $ret->size < 8 || $ret->size > 256)
            ) {
                throw new Exception('Invalid int/uint<N> width: ' . $ret->size);
            }
            return $ret;
        }
    }

}