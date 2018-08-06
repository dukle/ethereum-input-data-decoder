<?php

namespace TWOamigos;

class InputDataDecoder
{

    protected $abi = [];

    /**
     * Initialize abi interface
     *
     * @param array $abi
     */
    public function __construct(array $abi)
    {
        $this->abi = $abi;
    }

    // TODO: this function is never called, but needs to be checked
    public function decodeConstructor($data)
    {
        $data = trim($data);

        foreach ($this->abi as $obj) {
            if ($obj->type !== 'constructor') {
                continue;
            }

            $name = isset($obj->name) ? $obj->name : null;
            $types = isset($obj->inputs) && is_array($obj->inputs) ?
                array_map(function ($x) {
                    return $x->type;
                }, $obj->inputs) : [];


            // TODO: check this in php
            // take last 32 bytes
            $data = substr($data, -256);

            if (strlen($data) !== 256) {
                throw new \Exception('fial');
            }

            if (strpos($data, '0x') !== 0) {
                $data = '0x' . $data;
            }

            // TODO: delete when ethers .Interface.decodeParams is added
            $inputs = [];
            //TODO: needs to be added
//      $inputs = ethers .Interface.decodeParams(types, data)

            $result = new \stdClass();
            $result->name = $name;
            $result->types = $types;
            $result->inputs = $inputs;

            return $result;
        }

        throw new Error('not found');
    }

    /**
     * @param string $data
     * @return string
     */
    public function decodeData($data)
    {
        $data = is_string($data) ? $data : '';
        $data = trim($data);


        $dataBuf = hex2bin(str_replace('0x', '', $data));


//        $dataBufUnpacked = array_pop(unpack('H*', $dataBuf));

        $methodId = bin2hex(substr($dataBuf, 0, 4));
//        $methodId =  substr($dataBufUnpacked, 0, 8);


        $inputsBufBin = substr($dataBuf, 4);
        $inputsBuf = bin2hex($inputsBufBin);

        $result = array_reduce($this->abi, function ($acc, $obj) use ($methodId, $inputsBuf, $inputsBufBin) {

            if ($obj->type === 'constructor') return $acc;

            $name = isset($obj->name) ? $obj->name : 'null'; // The Buffer gets 'null' as string
            $types = isset($obj->inputs) ? array_map(function ($input) {
                return $input->type;
            }, $obj->inputs) : [];

            $hash = EthereumAbi::methodID($name, $types);


            if ($hash === $methodId) {
                // https://github.com/miguelmota/ethereum-input-data-decoder/issues/8
                if ($methodId === 'a9059cbb') {

                    //TODO: Check this
                    $inputsBuf = str_repeat('00', 12) . bin2hex(substr($inputsBufBin, 12, 32 - 12)) . bin2hex(substr($inputsBufBin, 32));
                }

                $inputs = EthereumAbi::rawDecode($types, $inputsBuf, $inputsBufBin);

                $return = new \stdClass();

                $return->name = $name;
                $return->types = $types;
                $return->inputs = $inputs;

                return $return;
            }

            return $acc;
        });


        if (!isset($result->name) || !$result->name) {
            try {
                $decoded = $this->decodeConstructor($data);
                if ($decoded) {
                    return $decoded;
                }
            } catch (\Exception $err) {}
        }

        return $result;
    }
}