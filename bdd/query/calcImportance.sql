SELECT e.id, e.label, e.description, e.sitelinks, e.statements, e.externalIds, pNait.value_str
, COUNT(DISTINCT pInf.entity_id) nbInflu
, (e.sitelinks + e.statements + e.externalIds) * (COUNT(DISTINCT pInf.entity_id)+1) importance 
FROM wikidata_entities e
INNER JOIN  wikidata_properties pNait ON pNait.entity_id = e.id and pNait.property = 'P569'
LEFT JOIN  wikidata_properties pInf ON pInf.value_id = e.id and pInf.property = 'P737'
WHERE SUBSTR(pNait.value_str, 7, 5) = "12-30"
GROUP BY e.id
ORDER BY importance DESC