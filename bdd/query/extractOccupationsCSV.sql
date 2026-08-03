SELECT o0.label, occu0.id, oSub0.label lbl0, occu0.subclass cls0
	, oSub1.label lbl1, occu1.subClass cls1
	, oSub2.label lbl2, occu2.subClass cls2
	, oSub3.label lbl3, occu3.subClass cls3
	, oSub4.label lbl4, occu4.subClass cls4
	, oSub5.label lbl5, occu5.subClass cls5
	, oSub6.label lbl6, occu6.subClass cls6
	, oSub7.label lbl7, occu7.subClass cls7
	, oSub8.label lbl8, occu8.subClass cls8
	, oSub9.label lbl9, occu9.subClass cls9
	, oSub10.label lbl10, occu10.subClass cls10
FROM
(SELECT
  o.id,
  sub.subclass
FROM wikidata_occupations o
JOIN JSON_TABLE(
  CONCAT('["', REPLACE(o.subclass_of, '|', '","'), '"]'),
  '$[*]' COLUMNS (subclass VARCHAR(100) PATH '$')
) sub) occu0
LEFT JOIN wikidata_occupations o0 on o0.id = occu0.id
LEFT JOIN wikidata_occupations oSub0 on oSub0.id = occu0.subclass
LEFT JOIN (SELECT
  o.id,
  sub.subclass
FROM wikidata_occupations o
JOIN JSON_TABLE(
  CONCAT('["', REPLACE(o.subclass_of, '|', '","'), '"]'),
  '$[*]' COLUMNS (subclass VARCHAR(100) PATH '$')
) sub) occu1 on occu1.id = occu0.subclass
LEFT JOIN wikidata_occupations oSub1 on oSub1.id = occu1.subclass
LEFT JOIN (SELECT
  o.id,
  sub.subclass
FROM wikidata_occupations o
JOIN JSON_TABLE(
  CONCAT('["', REPLACE(o.subclass_of, '|', '","'), '"]'),
  '$[*]' COLUMNS (subclass VARCHAR(100) PATH '$')
) sub) occu2 on occu2.id = occu1.subclass
LEFT JOIN wikidata_occupations oSub2 on oSub2.id = occu2.subclass
LEFT JOIN (SELECT
  o.id,
  sub.subclass
FROM wikidata_occupations o
JOIN JSON_TABLE(
  CONCAT('["', REPLACE(o.subclass_of, '|', '","'), '"]'),
  '$[*]' COLUMNS (subclass VARCHAR(100) PATH '$')
) sub) occu3 on occu3.id = occu2.subclass
LEFT JOIN wikidata_occupations oSub3 on oSub3.id = occu3.subclass
LEFT JOIN (SELECT
  o.id,
  sub.subclass
FROM wikidata_occupations o
JOIN JSON_TABLE(
  CONCAT('["', REPLACE(o.subclass_of, '|', '","'), '"]'),
  '$[*]' COLUMNS (subclass VARCHAR(100) PATH '$')
) sub) occu4 on occu4.id = occu3.subclass
LEFT JOIN wikidata_occupations oSub4 on oSub4.id = occu4.subclass
LEFT JOIN (SELECT
  o.id,
  sub.subclass
FROM wikidata_occupations o
JOIN JSON_TABLE(
  CONCAT('["', REPLACE(o.subclass_of, '|', '","'), '"]'),
  '$[*]' COLUMNS (subclass VARCHAR(100) PATH '$')
) sub) occu5 on occu5.id = occu4.subclass
LEFT JOIN wikidata_occupations oSub5 on oSub5.id = occu5.subclass
LEFT JOIN (SELECT
  o.id,
  sub.subclass
FROM wikidata_occupations o
JOIN JSON_TABLE(
  CONCAT('["', REPLACE(o.subclass_of, '|', '","'), '"]'),
  '$[*]' COLUMNS (subclass VARCHAR(100) PATH '$')
) sub) occu6 on occu6.id = occu5.subclass
LEFT JOIN wikidata_occupations oSub6 on oSub6.id = occu6.subclass
LEFT JOIN (SELECT
  o.id,
  sub.subclass
FROM wikidata_occupations o
JOIN JSON_TABLE(
  CONCAT('["', REPLACE(o.subclass_of, '|', '","'), '"]'),
  '$[*]' COLUMNS (subclass VARCHAR(100) PATH '$')
) sub) occu7 on occu7.id = occu6.subclass
LEFT JOIN wikidata_occupations oSub7 on oSub7.id = occu7.subclass
LEFT JOIN (SELECT
  o.id,
  sub.subclass
FROM wikidata_occupations o
JOIN JSON_TABLE(
  CONCAT('["', REPLACE(o.subclass_of, '|', '","'), '"]'),
  '$[*]' COLUMNS (subclass VARCHAR(100) PATH '$')
) sub) occu8 on occu8.id = occu7.subclass
LEFT JOIN wikidata_occupations oSub8 on oSub8.id = occu8.subclass
LEFT JOIN (SELECT
  o.id,
  sub.subclass
FROM wikidata_occupations o
JOIN JSON_TABLE(
  CONCAT('["', REPLACE(o.subclass_of, '|', '","'), '"]'),
  '$[*]' COLUMNS (subclass VARCHAR(100) PATH '$')
) sub) occu9 on occu9.id = occu8.subclass
LEFT JOIN wikidata_occupations oSub9 on oSub9.id = occu9.subclass
LEFT JOIN (SELECT
  o.id,
  sub.subclass
FROM wikidata_occupations o
JOIN JSON_TABLE(
  CONCAT('["', REPLACE(o.subclass_of, '|', '","'), '"]'),
  '$[*]' COLUMNS (subclass VARCHAR(100) PATH '$')
) sub) occu10 on occu10.id = occu9.subclass
LEFT JOIN wikidata_occupations oSub10 on oSub10.id = occu10.subclass
