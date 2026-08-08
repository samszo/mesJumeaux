WITH RECURSIVE class_tree AS (

    -- Classes directes de l'entité (valeurs P31)
    SELECT c.id, c.label, 0 AS depth
    FROM wikidata_nodes n
    JOIN wikidata_p279_class c
      ON FIND_IN_SET(c.id, REPLACE(n.p31, '|', ',')) > 0
    WHERE n.id = 'Q1000176'

    UNION ALL

    -- Superclasses via P279 (remonte la hiérarchie)
    SELECT g.dst_id, c.label, ct.depth + 1
    FROM class_tree ct
    JOIN wikidata_p279_graph g ON g.src_id = ct.id
    LEFT JOIN wikidata_p279_class c ON c.id = g.dst_id
    WHERE ct.depth < 20
)
SELECT t.id, MIN(t.depth) AS depth, n.label
FROM class_tree t
inner join wikidata_nodes n on n.id = t.id
GROUP BY t.id
ORDER BY t.depth, t.id;