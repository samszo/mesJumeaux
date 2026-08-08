SELECT e.id, e.label, e.description, e.sitelinks, e.statements, e.externalIds, e.wikipedia
, pNait.value_str
, GROUP_CONCAT(pOccu.value_id) gOccu
, COUNT(DISTINCT pInf.entity_id) nbInflu
, (e.sitelinks + e.statements + e.externalIds) * (COUNT(DISTINCT pInf.entity_id)+1) importance
, g.label lieu, g.latitude, g.longitude 
FROM wikidata_entities e
INNER JOIN  wikidata_properties pNait ON pNait.entity_id = e.id and pNait.property = 'P569'
LEFT JOIN  wikidata_properties pInf ON pInf.value_id = e.id and pInf.property = 'P737'
LEFT JOIN  wikidata_properties pOccu ON pOccu.entity_id = e.id and pOccu.property = 'P106'
LEFT JOIN  wikidata_properties pGeo ON pGeo.entity_id = e.id and pGeo.property = 'P19'
LEFT JOIN  wikidata_geos g ON g.id = pGeo.value_id
WHERE SUBSTR(pNait.value_str, 7, 5) = "12-30"
GROUP BY e.id
ORDER BY importance DESC