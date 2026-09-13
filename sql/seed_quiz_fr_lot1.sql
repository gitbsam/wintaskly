-- =====================================================================
-- Wintaskly — banque de questions WintQuiz (francais), lot 1
--
-- 141 questions, huit categories, trois niveaux de difficulte.
--
-- Les propositions ont ete melangees : dans la redaction d'origine, la
-- bonne reponse tombait en B dans 70 % des cas. Repondre toujours B
-- aurait suffi a gagner sept questions sur dix — et un joueur l'aurait
-- remarque en une journee.
--
-- Import rejouable : une question deja presente n'est pas dupliquee,
-- la comparaison portant sur le texte de la question.
-- =====================================================================

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel est le plus long fleuve du monde ?','L\'Amazone','Le Mississippi','Le Yangtsé','Le Nil','a','Les mesures récentes donnent l\'Amazone devant le Nil, mais le débat reste ouvert selon la source retenue.','géographie',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel est le plus long fleuve du monde ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle est la capitale de l\'Australie ?','Sydney','Canberra','Perth','Melbourne','b','Canberra a été choisie en 1908 comme compromis entre Sydney et Melbourne.','géographie',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle est la capitale de l\'Australie ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Sur quel continent se trouve le désert du Kalahari ?','Amérique du Sud','Asie','Afrique','Océanie','c','Il s\'étend sur le Botswana, la Namibie et l\'Afrique du Sud.','géographie',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Sur quel continent se trouve le désert du Kalahari ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel pays compte le plus d\'habitants au monde ?','L\'Indonésie','La Chine','Les États-Unis','L\'Inde','d','L\'Inde a dépassé la Chine en 2023 selon les Nations unies.','géographie',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel pays compte le plus d\'habitants au monde ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel océan borde la côte ouest de l\'Afrique ?','L\'océan Arctique','L\'océan Indien','L\'océan Pacifique','L\'océan Atlantique','d','','géographie',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel océan borde la côte ouest de l\'Afrique ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Dans quel pays se trouve le Machu Picchu ?','L\'Équateur','Le Pérou','La Bolivie','Le Mexique','b','Cette cité inca date du XVe siècle.','géographie',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Dans quel pays se trouve le Machu Picchu ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle est la plus haute montagne d\'Afrique ?','Le mont Kenya','L\'Atlas','Le Ruwenzori','Le Kilimandjaro','d','Il culmine à 5 895 mètres en Tanzanie.','géographie',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle est la plus haute montagne d\'Afrique ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel pays a la forme d\'une botte ?','L\'Italie','La Grèce','L\'Espagne','Le Portugal','a','','géographie',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel pays a la forme d\'une botte ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien de pays composent l\'Amérique du Sud ?','Huit','Douze','Quinze','Dix','b','Douze États souverains, plus la Guyane française qui est un département.','géographie',3,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien de pays composent l\'Amérique du Sud ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle mer sépare l\'Europe de l\'Afrique ?','La mer Rouge','La mer Noire','La mer Baltique','La Méditerranée','d','','géographie',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle mer sépare l\'Europe de l\'Afrique ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel est le plus petit pays du monde ?','Le Vatican','Nauru','Monaco','Saint-Marin','a','Le Vatican fait 0,44 km².','géographie',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel est le plus petit pays du monde ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Dans quel pays coule le Gange ?','Le Pakistan','Le Népal','Le Bangladesh','L\'Inde','d','Il traverse aussi le Bangladesh avant de rejoindre le golfe du Bengale.','géographie',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Dans quel pays coule le Gange ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle est la capitale du Canada ?','Toronto','Vancouver','Montréal','Ottawa','d','Toronto est la plus grande ville, mais Ottawa est la capitale.','géographie',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle est la capitale du Canada ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel détroit sépare l\'Europe de l\'Asie à Istanbul ?','Le Bosphore','Le Pas de Calais','Le détroit de Malacca','Le détroit de Gibraltar','a','','géographie',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel détroit sépare l\'Europe de l\'Asie à Istanbul ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel pays africain était appelé Abyssinie ?','L\'Éthiopie','Le Soudan','La Somalie','Le Kenya','a','','géographie',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel pays africain était appelé Abyssinie ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle est la plus grande île du monde ?','Le Groenland','Madagascar','Bornéo','La Nouvelle-Guinée','a','L\'Australie est considérée comme un continent, pas une île.','géographie',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle est la plus grande île du monde ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel pays possède le plus de fuseaux horaires ?','Les États-Unis','La Chine','La Russie','La France','d','Avec ses territoires d\'outre-mer, la France en compte douze.','géographie',3,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel pays possède le plus de fuseaux horaires ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Dans quelle ville se trouve le Colisée ?','Rome','Milan','Athènes','Naples','a','','géographie',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Dans quelle ville se trouve le Colisée ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel fleuve traverse Paris ?','La Loire','La Seine','La Garonne','Le Rhône','b','','géographie',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel fleuve traverse Paris ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle est la capitale du Japon ?','Osaka','Nagoya','Tokyo','Kyoto','c','Kyoto l\'a été jusqu\'en 1868.','géographie',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle est la capitale du Japon ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'En quelle année a eu lieu la Révolution française ?','1804','1799','1789','1776','c','La prise de la Bastille date du 14 juillet 1789.','histoire',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'En quelle année a eu lieu la Révolution française ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Qui était le premier président des États-Unis ?','Abraham Lincoln','John Adams','George Washington','Thomas Jefferson','c','','histoire',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Qui était le premier président des États-Unis ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'En quelle année le mur de Berlin est-il tombé ?','1993','1991','1987','1989','d','Dans la nuit du 9 novembre 1989.','histoire',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'En quelle année le mur de Berlin est-il tombé ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle civilisation a construit les pyramides de Gizeh ?','Les Égyptiens','Les Grecs','Les Romains','Les Perses','a','','histoire',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle civilisation a construit les pyramides de Gizeh ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Qui a peint la Joconde ?','Botticelli','Michel-Ange','Raphaël','Léonard de Vinci','d','','histoire',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Qui a peint la Joconde ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'En quelle année a commencé la Première Guerre mondiale ?','1918','1916','1914','1912','c','','histoire',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'En quelle année a commencé la Première Guerre mondiale ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel empereur français a été exilé à Sainte-Hélène ?','Napoléon Ier','Napoléon III','Louis XVI','Charles X','a','Il y meurt en 1821.','histoire',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel empereur français a été exilé à Sainte-Hélène ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle route commerciale reliait la Chine à l\'Europe ?','La route des épices','La route de la soie','La route de l\'ambre','La route du sel','b','','histoire',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle route commerciale reliait la Chine à l\'Europe ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Qui a dirigé le mouvement d\'indépendance indien par la non-violence ?','Bose','Gandhi','Nehru','Ambedkar','b','','histoire',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Qui a dirigé le mouvement d\'indépendance indien par la non-violence ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'En quelle année l\'homme a-t-il marché sur la Lune ?','1969','1972','1975','1965','a','Le 20 juillet 1969, mission Apollo 11.','histoire',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'En quelle année l\'homme a-t-il marché sur la Lune ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel mur séparait l\'Empire romain des tribus du nord de la Bretagne ?','Le mur d\'Hadrien','La muraille de Servius','Le limes','Le mur d\'Aurélien','a','','histoire',3,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel mur séparait l\'Empire romain des tribus du nord de la Bretagne ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle reine d\'Égypte a régné en dernier avant l\'annexion romaine ?','Nitocris','Cléopâtre VII','Néfertiti','Hatchepsout','b','','histoire',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle reine d\'Égypte a régné en dernier avant l\'annexion romaine ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel événement marque la fin de la Seconde Guerre mondiale en Europe ?','La conférence de Yalta','La bataille de Stalingrad','La capitulation allemande de mai 1945','Le débarquement de Normandie','c','','histoire',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel événement marque la fin de la Seconde Guerre mondiale en Europe ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel navigateur a réalisé le premier tour du monde ?','Amerigo Vespucci','Christophe Colomb','L\'expédition de Magellan','Vasco de Gama','c','Magellan est mort en route, Elcano a terminé le voyage.','histoire',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel navigateur a réalisé le premier tour du monde ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle dynastie a construit la Cité interdite à Pékin ?','Les Han','Les Tang','Les Qing','Les Ming','d','Achevée en 1420.','histoire',3,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle dynastie a construit la Cité interdite à Pékin ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel est le symbole chimique de l\'or ?','Go','Ag','Au','Or','c','Du latin aurum.','sciences',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel est le symbole chimique de l\'or ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien de planètes compte le système solaire ?','Huit','Neuf','Sept','Dix','a','Pluton a été reclassée en planète naine en 2006.','sciences',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien de planètes compte le système solaire ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel gaz les plantes absorbent-elles pour la photosynthèse ?','Le dioxyde de carbone','L\'hydrogène','L\'oxygène','L\'azote','a','','sciences',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel gaz les plantes absorbent-elles pour la photosynthèse ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle est la vitesse de la lumière dans le vide ?','150 000 km/s','1 000 000 km/s','500 000 km/s','300 000 km/s','d','Environ 299 792 km/s.','sciences',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle est la vitesse de la lumière dans le vide ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel organe produit l\'insuline ?','Le foie','Les reins','Le pancréas','La rate','c','','sciences',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel organe produit l\'insuline ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien d\'os compte le squelette humain adulte ?','306','406','106','206','d','Le nouveau-né en a davantage, certains fusionnent avec l\'âge.','sciences',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien d\'os compte le squelette humain adulte ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle planète est la plus proche du Soleil ?','Vénus','Mercure','La Terre','Mars','b','','sciences',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle planète est la plus proche du Soleil ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel scientifique a formulé la théorie de la relativité ?','Galilée','Newton','Bohr','Einstein','d','','sciences',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel scientifique a formulé la théorie de la relativité ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'De quoi est principalement composée l\'atmosphère terrestre ?','D\'hydrogène','D\'oxygène','De dioxyde de carbone','D\'azote','d','Environ 78 % d\'azote contre 21 % d\'oxygène.','sciences',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'De quoi est principalement composée l\'atmosphère terrestre ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel est l\'élément le plus abondant dans l\'univers ?','Le carbone','L\'oxygène','L\'hélium','L\'hydrogène','d','','sciences',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel est l\'élément le plus abondant dans l\'univers ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle partie de l\'œil contrôle la quantité de lumière ?','La rétine','Le cristallin','L\'iris','La cornée','c','','sciences',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle partie de l\'œil contrôle la quantité de lumière ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel est le métal liquide à température ambiante ?','Le zinc','Le mercure','Le plomb','L\'étain','b','','sciences',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel est le métal liquide à température ambiante ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien de chambres compte le cœur humain ?','Quatre','Trois','Deux','Cinq','a','Deux oreillettes et deux ventricules.','sciences',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien de chambres compte le cœur humain ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle force maintient les planètes en orbite ?','Le magnétisme','L\'électricité statique','La force nucléaire','La gravitation','d','','sciences',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle force maintient les planètes en orbite ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel est le plus grand organe du corps humain ?','La peau','Le foie','Les poumons','L\'intestin','a','','sciences',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel est le plus grand organe du corps humain ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'À quelle température l\'eau bout-elle au niveau de la mer ?','110 °C','120 °C','90 °C','100 °C','d','En altitude, elle bout plus bas.','sciences',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'À quelle température l\'eau bout-elle au niveau de la mer ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel animal est le plus grand du monde ?','Le requin-baleine','La baleine bleue','L\'éléphant d\'Afrique','La girafe','b','','sciences',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel animal est le plus grand du monde ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Que mesure l\'échelle de Richter ?','L\'acidité','La température','La vitesse du vent','La magnitude des séismes','d','','sciences',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Que mesure l\'échelle de Richter ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel groupe sanguin est donneur universel ?','B','A','AB','O négatif','d','','sciences',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel groupe sanguin est donneur universel ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Que signifie le sigle URL ?','Unified Router Line','Uniform Resource Locator','User Restricted Login','Universal Reading Link','b','','technologie',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Que signifie le sigle URL ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel protocole sécurise les échanges d\'un site web ?','FTP','POP3','HTTPS','SMTP','c','Le S signifie « secure ».','technologie',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel protocole sécurise les échanges d\'un site web ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Que stocke un cookie dans un navigateur ?','De petites données liées à un site','Des images','Des programmes','Des mots de passe en clair','a','','technologie',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Que stocke un cookie dans un navigateur ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Que signifie « Wi-Fi » dans l\'usage courant ?','Une marque de réseau sans fil','Un type de processeur','Un câble réseau','Un format d\'image','a','C\'est un nom commercial, pas un acronyme officiel.','technologie',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Que signifie « Wi-Fi » dans l\'usage courant ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle société a créé le système Android ?','Google','Apple','Samsung','Microsoft','a','Google l\'a racheté en 2005.','technologie',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle société a créé le système Android ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Que mesure-t-on en mégaoctets ?','La quantité de données','La résolution','La vitesse','La fréquence','a','','technologie',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Que mesure-t-on en mégaoctets ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel est le rôle d\'un VPN ?','Accélérer la connexion','Supprimer la publicité','Chiffrer et rediriger le trafic','Augmenter la mémoire','c','','technologie',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel est le rôle d\'un VPN ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Que signifie « open source » ?','Gratuit','Sans publicité','Dont le code est consultable et réutilisable','Réservé aux professionnels','c','Un logiciel open source peut être payant.','technologie',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Que signifie « open source » ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel langage sert à structurer une page web ?','CSS','SQL','PHP','HTML','d','CSS gère l\'apparence, HTML la structure.','technologie',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel langage sert à structurer une page web ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Qu\'est-ce que le hameçonnage ?','Une panne de serveur','Une tentative de vol d\'identifiants par tromperie','Un virus informatique','Un type de pare-feu','b','','technologie',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Qu\'est-ce que le hameçonnage ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Que fait un moteur de recherche ?','Il crée des sites','Il héberge les sites','Il indexe et classe des pages web','Il fournit la connexion','c','','technologie',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Que fait un moteur de recherche ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien de bits contient un octet ?','Seize','Quatre','Six','Huit','d','','technologie',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien de bits contient un octet ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'À quoi sert l\'authentification à deux facteurs ?','À ajouter une preuve d\'identité au mot de passe','À changer de mot de passe','À accélérer la connexion','À bloquer la publicité','a','','technologie',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'À quoi sert l\'authentification à deux facteurs ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Que signifie « bande passante » ?','La taille d\'un écran','Le nombre d\'utilisateurs','La durée d\'un abonnement','La quantité de données transmissibles par seconde','d','','technologie',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Que signifie « bande passante » ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel format d\'image gère la transparence ?','PNG','JPEG','GIF animé uniquement','BMP','a','Le GIF gère aussi une transparence, mais binaire.','technologie',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel format d\'image gère la transparence ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Qu\'est-ce qu\'un navigateur web ?','Un logiciel qui affiche des pages web','Un site internet','Un moteur de recherche','Un fournisseur d\'accès','a','','technologie',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Qu\'est-ce qu\'un navigateur web ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Que veut dire « télécharger » ?','Compresser un fichier','Envoyer un fichier vers un serveur','Recevoir un fichier depuis un serveur','Supprimer un fichier','c','L\'envoi se dit « téléverser ».','technologie',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Que veut dire « télécharger » ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle est la fonction principale d\'un pare-feu ?','Filtrer les connexions réseau','Sauvegarder les fichiers','Refroidir l\'ordinateur','Accélérer l\'affichage','a','','technologie',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle est la fonction principale d\'un pare-feu ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Qu\'est-ce qu\'une adresse IP ?','Un logiciel antivirus','Un type de fichier','Un identifiant numérique d\'appareil sur un réseau','Un mot de passe','c','','technologie',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Qu\'est-ce qu\'une adresse IP ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle touche permet généralement de rafraîchir une page ?','F5','F1','F8','F12','a','','technologie',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle touche permet généralement de rafraîchir une page ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien de lettres compte l\'alphabet français ?','Vingt-six','Vingt-huit','Vingt-quatre','Trente','a','','langue',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien de lettres compte l\'alphabet français ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel est le pluriel de « cheval » ?','Chevals','Chevaux','Chevaus','Chevales','b','','langue',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel est le pluriel de « cheval » ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Que signifie l\'expression « tomber dans les pommes » ?','S\'évanouir','Se tromper','Se fâcher','Réussir','a','','langue',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Que signifie l\'expression « tomber dans les pommes » ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel mot est un synonyme de « rapide » ?','Lourd','Lent','Véloce','Épais','c','','langue',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel mot est un synonyme de « rapide » ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel est le participe passé du verbe « résoudre » ?','Résous','Résolu','Résoudu','Résolvé','b','','langue',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel est le participe passé du verbe « résoudre » ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien de temps dure un lustre ?','Cinq ans','Dix ans','Deux ans','Vingt ans','a','','langue',3,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien de temps dure un lustre ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Que désigne un « palindrome » ?','Un mot étranger','Un mot qui se lit dans les deux sens','Un mot inventé','Un mot très long','b','« Kayak » en est un.','langue',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Que désigne un « palindrome » ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel est le féminin de « ambassadeur » ?','Ambassadère','Ambassadrice','Ambassadeure','Ambassadeuse','b','','langue',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel est le féminin de « ambassadeur » ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Que signifie « éphémère » ?','Qui revient chaque année','Qui ne dure pas','Qui se répète','Qui dure longtemps','b','','langue',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Que signifie « éphémère » ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel signe de ponctuation termine une interrogation ?','Le point d\'exclamation','Les deux-points','Le point','Le point d\'interrogation','d','','langue',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel signe de ponctuation termine une interrogation ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien font 15 % de 200 ?','30','20','15','45','a','','maths',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien font 15 % de 200 ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel est le résultat de 7 × 8 ?','56','64','54','48','a','','maths',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel est le résultat de 7 × 8 ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien de côtés compte un hexagone ?','Huit','Six','Sept','Cinq','b','','maths',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien de côtés compte un hexagone ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle est la racine carrée de 144 ?','13','14','11','12','d','','maths',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle est la racine carrée de 144 ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien font 2 puissance 10 ?','512','2 048','1 000','1 024','d','','maths',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien font 2 puissance 10 ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel est le prochain nombre : 2, 4, 8, 16, ... ?','48','32','30','24','b','La suite double à chaque étape.','maths',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel est le prochain nombre : 2, 4, 8, 16, ... ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien de minutes y a-t-il dans une journée ?','1 440','1 200','2 400','1 800','a','24 × 60.','maths',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien de minutes y a-t-il dans une journée ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Un article coûte 80 €, remise de 25 %. Quel prix payez-vous ?','60 €','65 €','70 €','55 €','a','','maths',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Un article coûte 80 €, remise de 25 %. Quel prix payez-vous ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle est la somme des angles d\'un triangle ?','360 degrés','90 degrés','270 degrés','180 degrés','d','','maths',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle est la somme des angles d\'un triangle ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien font 0,1 + 0,2 ?','0,3','0,12','0,03','0,2','a','','maths',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien font 0,1 + 0,2 ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Si un train roule à 120 km/h, quelle distance en 30 minutes ?','60 km','120 km','40 km','80 km','a','','maths',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Si un train roule à 120 km/h, quelle distance en 30 minutes ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel est le plus petit nombre premier ?','2','3','1','0','a','1 n\'est pas premier par définition.','maths',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel est le plus petit nombre premier ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel est l\'animal terrestre le plus rapide ?','Le lion','Le cheval','Le guépard','L\'antilope','c','Jusqu\'à 110 km/h sur de courtes distances.','nature',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel est l\'animal terrestre le plus rapide ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien de pattes a une araignée ?','Huit','Dix','Six','Douze','a','Les insectes en ont six, les arachnides huit.','nature',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien de pattes a une araignée ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel oiseau ne peut pas voler ?','Le faucon','Le héron','Le martinet','L\'autruche','d','','nature',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel oiseau ne peut pas voler ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Que produisent les abeilles ?','De la soie','De la cire uniquement','Du lait','Du miel','d','Elles produisent aussi de la cire.','nature',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Que produisent les abeilles ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel arbre produit les glands ?','Le chêne','Le bouleau','Le hêtre','Le platane','a','','nature',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel arbre produit les glands ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien de temps dure la gestation d\'une éléphante ?','18 mois','12 mois','22 mois','9 mois','c','Près de deux ans, la plus longue chez les mammifères terrestres.','nature',3,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien de temps dure la gestation d\'une éléphante ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel animal est le symbole du WWF ?','Le panda géant','Le tigre','Le gorille','L\'éléphant','a','','nature',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel animal est le symbole du WWF ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'De quoi se nourrit principalement le koala ?','De feuilles d\'eucalyptus','De poisson','De bambou','D\'insectes','a','','nature',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'De quoi se nourrit principalement le koala ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel est le plus grand mammifère terrestre ?','La girafe','L\'hippopotame','Le rhinocéros','L\'éléphant d\'Afrique','d','','nature',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel est le plus grand mammifère terrestre ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien de cœurs possède une pieuvre ?','Trois','Deux','Quatre','Un','a','Deux pour les branchies, un pour le reste du corps.','nature',3,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien de cœurs possède une pieuvre ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel insecte transmet le paludisme ?','Le moustique anophèle','La puce','La tique','La mouche','a','','nature',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel insecte transmet le paludisme ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle est la durée de vie moyenne d\'une abeille ouvrière en été ?','Cinq ans','Environ six semaines','Un an','Quelques jours','b','','nature',3,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle est la durée de vie moyenne d\'une abeille ouvrière en été ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel animal change de couleur pour se camoufler ?','Le lézard vert','Le crocodile','La salamandre','Le caméléon','d','Il change aussi selon son humeur et la température.','nature',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel animal change de couleur pour se camoufler ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Les dauphins sont-ils des poissons ?','Oui, des poissons à poumons','Non, ce sont des mammifères','Ce sont des reptiles','Oui','b','','nature',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Les dauphins sont-ils des poissons ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel arbre est le plus haut du monde ?','Le baobab','Le séquoia à feuilles d\'if','Le chêne','L\'eucalyptus','b','Certains dépassent 115 mètres.','nature',3,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel arbre est le plus haut du monde ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Tous les combien d\'années ont lieu les Jeux olympiques d\'été ?','Cinq ans','Deux ans','Quatre ans','Trois ans','c','','sport',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Tous les combien d\'années ont lieu les Jeux olympiques d\'été ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien de joueurs compte une équipe de football sur le terrain ?','Dix','Neuf','Onze','Douze','c','','sport',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien de joueurs compte une équipe de football sur le terrain ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Dans quel sport marque-t-on un « ace » ?','Le basket','Le rugby','Le football','Le tennis','d','Un service gagnant que l\'adversaire ne touche pas.','sport',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Dans quel sport marque-t-on un « ace » ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien de points vaut un panier à trois points ?','Trois','Quatre','Deux','Un','a','','sport',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien de points vaut un panier à trois points ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel pays a inventé le judo ?','La Chine','Le Japon','La Thaïlande','La Corée','b','','sport',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel pays a inventé le judo ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle est la distance d\'un marathon ?','100 km','42,195 km','50 km','21,1 km','b','','sport',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle est la distance d\'un marathon ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Dans quel sport utilise-t-on un volant ?','Le squash','Le badminton','Le ping-pong','Le tennis','b','','sport',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Dans quel sport utilise-t-on un volant ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien de joueurs compte une équipe de rugby à XV ?','Onze','Quinze','Treize','Dix-sept','b','','sport',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien de joueurs compte une équipe de rugby à XV ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel sport se pratique sur un tatami ?','Le judo','La boxe','L\'escrime','Le tir à l\'arc','a','','sport',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel sport se pratique sur un tatami ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien dure un match de football réglementaire ?','100 minutes','60 minutes','80 minutes','90 minutes','d','Deux mi-temps de 45 minutes.','sport',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien dure un match de football réglementaire ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Qui a composé la Symphonie n° 9 dite « Ode à la joie » ?','Mozart','Chopin','Bach','Beethoven','d','','culture',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Qui a composé la Symphonie n° 9 dite « Ode à la joie » ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien de cordes compte une guitare classique ?','Cinq','Six','Quatre','Sept','b','','culture',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien de cordes compte une guitare classique ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Qui a écrit « Les Misérables » ?','Gustave Flaubert','Émile Zola','Victor Hugo','Honoré de Balzac','c','','culture',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Qui a écrit « Les Misérables » ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel instrument possède des touches noires et blanches ?','Le piano','Le violon','La harpe','La flûte','a','','culture',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel instrument possède des touches noires et blanches ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Dans quel musée se trouve la Joconde ?','Les Offices','Le Prado','Le British Museum','Le Louvre','d','','culture',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Dans quel musée se trouve la Joconde ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Qui a écrit « Le Petit Prince » ?','Marcel Pagnol','Jules Verne','Albert Camus','Antoine de Saint-Exupéry','d','Publié en 1943.','culture',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Qui a écrit « Le Petit Prince » ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien de notes compte une gamme de do majeur ?','Cinq','Sept','Six','Huit','b','Do ré mi fa sol la si.','culture',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien de notes compte une gamme de do majeur ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quel peintre a coupé une partie de son oreille ?','Van Gogh','Cézanne','Gauguin','Monet','a','','culture',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quel peintre a coupé une partie de son oreille ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle est la langue la plus parlée au monde comme langue maternelle ?','L\'anglais','Le mandarin','L\'espagnol','L\'hindi','b','L\'anglais domine en nombre total de locuteurs.','culture',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle est la langue la plus parlée au monde comme langue maternelle ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Qui a sculpté « Le Penseur » ?','Maillol','Rodin','Giacometti','Camille Claudel','b','','culture',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Qui a sculpté « Le Penseur » ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Que signifie « taux d\'intérêt » ?','Le coût d\'emprunter de l\'argent','Une taxe d\'État','Le prix d\'une action','Le prix d\'un produit','a','','finance',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Que signifie « taux d\'intérêt » ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Qu\'est-ce que l\'inflation ?','Une baisse des prix','Une hausse des salaires','Une baisse des impôts','Une hausse générale des prix','d','','finance',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Qu\'est-ce que l\'inflation ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Que veut dire « épargner » ?','Investir en bourse','Dépenser davantage','Mettre de l\'argent de côté','Emprunter','c','','finance',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Que veut dire « épargner » ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Quelle monnaie est utilisée au Japon ?','Le yuan','Le yen','Le baht','Le won','b','','finance',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Quelle monnaie est utilisée au Japon ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Que signifie « budget » ?','Un impôt','Une prévision de recettes et de dépenses','Un compte bancaire','Une dette','b','','finance',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Que signifie « budget » ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Combien de centimes vaut un euro ?','Dix','Cent','Cinquante','Mille','b','','finance',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Combien de centimes vaut un euro ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Qu\'est-ce qu\'un IBAN ?','Un identifiant international de compte bancaire','Un impôt','Un mot de passe bancaire','Un type de carte','a','','finance',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Qu\'est-ce qu\'un IBAN ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Que signifie « diversifier ses placements » ?','Emprunter davantage','Répartir pour réduire le risque','Vendre rapidement','Tout mettre au même endroit','b','','finance',2,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Que signifie « diversifier ses placements » ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Qu\'est-ce qu\'une cryptomonnaie ?','Une carte prépayée','Une monnaie imprimée par une banque','Une monnaie numérique décentralisée','Un compte d\'épargne','c','','finance',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Qu\'est-ce qu\'une cryptomonnaie ?' AND `lang` = 'fr'));

INSERT INTO `quiz_questions` (`question`,`a`,`b`,`c`,`d`,`correct`,`explanation`,`category`,`difficulty`,`lang`,`active`)
SELECT 'Que veut dire « solde » sur un compte ?','Le montant disponible','Le taux appliqué','Le montant des frais','Le montant emprunté','a','','finance',1,'fr',1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1) x
   WHERE EXISTS (SELECT 1 FROM `quiz_questions` WHERE `question` = 'Que veut dire « solde » sur un compte ?' AND `lang` = 'fr'));

