<?php

	class XMLImporterHelpers {
		static function markdownify($string) {
			require_once(EXTENSIONS . '/xmlimporter/lib/markdownify/markdownify_extra.php');
			$markdownify = new Markdownify(true, MDFY_BODYWIDTH, false);

			$markdown = $markdownify->parseString($string);
			$markdown = htmlspecialchars($markdown, ENT_NOQUOTES, 'UTF-8');
			return $markdown;
		}

		static function dateFlip($string){
			$value = implode('/', array_reverse(explode('/', strtok($string, ' '))));
			return $value;
		}
                
                //temporary function to help import wordpress FAQs and attribute them to symphony authors
                static function convertAuthors($string){
                    $authors = array('admin' => 1,
                        'anthonyp' => 3,
                        'danem' => 4,
                        'derekm' => 5,
                        'garyr' => 6,
                        'gregl' => 2,
                        'johnw' => 7,
                        'jonathant' => 13,
                        'kailey' => 8,
                        'kylep' => 9,
                        'michael' => 10,
                        'mikem' => 11,
                        'stevenw' => 12);
                    if(isset($authors[$string])){
                        return array($authors[$string]);
                    }
                    return array(1);
                }
                
                /*
                 * Convert newsletter issue name to system id for importing
                 * Newsletter Articles and associating them with their parent Newsletter
                 */
                static function convertNewsletter($string){
                    $fields = Symphony::Database()->fetch("SELECT `entry_id` FROM `sym_entries_data_152` WHERE `value` = '".$string."'");
                    if($fields){
                        return $fields[0];
                    }
                    return $string;
                }
                
                static function multiJSONify($values, $map = ''){
                    $data = array();
                    $map = json_decode($map,true);
                    foreach($values as $value){
                                                
                        $xml = simplexml_load_string($value);

                        foreach($map[0] as $key => $value){
                            if($value == 'value'){
                                $data[$key][] = (string) $xml;
                            }else if($xml->attributes()->{$value}){
                                $data[$key][] = (string) $xml->attributes()->{$value};
                            }
                        }
                    }
                    
                    return json_encode(array($data));
                }
        }

