<?php
function convertToUtf8($data) {
  foreach ($data as $key => $value) {
    if (is_string($value)) {
      $encoding = mb_detect_encoding($value, ['UTF-8', 'Windows-1251', 'ISO-8859-1'], true);
      if ($encoding && $encoding !== 'UTF-8') {
        $data[$key] = mb_convert_encoding($value, 'UTF-8', $encoding);
      }
    }
  }
  return $data;
}
