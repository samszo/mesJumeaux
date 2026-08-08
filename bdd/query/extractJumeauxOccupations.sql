SELECT 
o.id, o.label
, GROUP_CONCAT(subclass.id) gClassId
, GROUP_CONCAT(n.label) gClassLabel 
FROM
(SELECT
	DISTINCT sub.subclass id
  -- o.id, count(*) nb -- o.label,
  -- , GROUP_CONCAT(n.id) nIds, GROUP_CONCAT(n.label) nLabels
  -- , n.label
FROM wikidata_occupations o
INNER JOIN  wikidata_properties pOccu ON pOccu.value_id = o.id and pOccu.property = 'P106'
INNER JOIN  wikidata_properties pNait ON pNait.entity_id = pOccu.entity_id and pNait.property = 'P569' AND SUBSTR(pNait.value_str, 7, 5) = "12-30"
JOIN JSON_TABLE(
  CONCAT('["', REPLACE(o.subclass_of, '|', '","'), '"]'),
  '$[*]' COLUMNS (subclass VARCHAR(100) PATH '$')
) sub) subclass
INNER JOIN wikidata_occupations o ON FIND_IN_SET(subclass.id, REPLACE(o.subclass_of, '|', ',')) > 0
LEFT JOIN wikidata_nodes n on n.id = subclass.id
GROUP BY o.id