select count(DISTINCT e.id) tot,
    count(DISTINCT eTo.id) toCalc,
    count(DISTINCT eCalc.id) calc
from wikidata_entities e
    left JOIN wikidata_entities eTo ON eTo.id = e.id
    AND eTo.dump_line = 0
    left JOIN wikidata_entities eCalc ON eCalc.id = e.id
    AND eCalc.dump_line > 0;