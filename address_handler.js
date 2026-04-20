const addressData = {
    "NCR": {
        "Metro Manila": ["Manila", "Quezon City", "Caloocan", "Las Piñas", "Makati", "Malabon", "Mandaluyong", "Marikina", "Muntinlupa", "Navotas", "Parañaque", "Pasay", "Pasig", "San Juan", "Taguig", "Valenzuela", "Pateros"]
    },
    "CAR": {
        "Abra": ["Bangued", "Boliney", "Bucay", "Bucloc", "Daguioman", "Danglas", "Dolores", "La Paz", "Lacub", "Lagangilang", "Lagayan", "Langiden", "Licuan-Baay", "Luba", "Malibcong", "Manabo", "Peñarrubia", "Pidigan", "Pilar", "Sallapadan", "San Isidro", "San Juan", "San Quintin", "Tayum", "Tineg", "Tubo", "Villaviciosa"],
        "Apayao": ["Calanasan", "Conner", "Flora", "Kabugao", "Luna", "Pudtol", "Santa Marcela"],
        "Benguet": ["Atok", "Baguio", "Bakun", "Bokod", "Buguias", "Itogon", "Kabayan", "Kapangan", "Kibungan", "La Trinidad", "Mankayan", "Sablan", "Tuba", "Tublay"],
        "Ifugao": ["Aguinaldo", "Alfonso Lista", "Asipulo", "Banaue", "Hingyon", "Hungduan", "Kiangan", "Lagawe", "Lamut", "Mayoyao", "Tinoc"],
        "Kalinga": ["Balbalan", "Lubuagan", "Pasil", "Pinukpuk", "Rizal", "Tabuk", "Tanudan", "Tinglayan"],
        "Mountain Province": ["Barlig", "Bontoc", "Bauko", "Besao", "Kayan", "Natonin", "Paracelis", "Sabangan", "Sadanga", "Sagada", "Tadian"]
    },
    "Region I": {
        "Ilocos Norte": ["Laoag", "Batac", "Bacarra", "Badoc", "Bangui", "Currimao", "Dingras", "Paoay", "Pagudpud"],
        "Ilocos Sur": ["Vigan", "Candon", "Narvacan", "Santa Maria", "Tagudin"],
        "La Union": ["San Fernando", "Agoo", "Bauang", "Rosario"],
        "Pangasinan": ["Dagupan", "San Carlos", "Urdaneta", "Alaminos", "Lingayen", "Calasiao", "Mangaldan"]
    },
    "Region II": {
        "Cagayan": ["Tuguegarao", "Aparri", "Lal-lo", "Solana"],
        "Isabela": ["Ilagan", "Cauayan", "Santiago", "Alicia", "Roxas"],
        "Nueva Vizcaya": ["Bayombong", "Solano", "Bambang"],
        "Quirino": ["Cabarroguis", "Maddela"]
    },
    "Region III": {
        "Aurora": ["Baler", "Casiguran", "Dilasag", "Dinalungan", "Dingalan", "Dipaculao", "Maria Aurora", "San Luis"],
        "Bataan": ["Abucay", "Bagac", "Balanga", "Dinalupihan", "Hermosa", "Limay", "Mariveles", "Morong", "Orani", "Orion", "Pilar", "Samal"],
        "Bulacan": ["Angat", "Balagtas", "Baliuag", "Bocaue", "Bulakan", "Bustos", "Calumpit", "Doña Remedios Trinidad", "Guiguinto", "Hagonoy", "Malolos City", "Marilao", "Meycauayan City", "Norzagaray", "Obando", "Pandi", "Paombong", "Plaridel", "Pulilan", "San Ildefonso", "San Jose del Monte City", "San Miguel", "San Rafael", "Santa Maria"],
        "Nueva Ecija": ["Aliaga", "Bongabon", "Cabanatuan City", "Cabiao", "Carranglan", "Cuyapo", "Gabaldon", "Gapan City", "General Mamerto Natividad", "General Tinio", "Guimba", "Jaen", "Laur", "Licab", "Llanera", "Lupao", "Muñoz Science City", "Nampicuan", "Palayan City", "Pantabangan", "Peñaranda", "Quezon", "Rizal", "San Antonio", "San Isidro", "San Jose City", "San Leonardo", "Santa Rosa", "Santo Domingo", "Talavera", "Talugtug", "Zaragoza"],
        "Pampanga": ["Angeles City", "Apalit", "Arayat", "Bacolor", "Candaba", "Floridablanca", "Guagua", "Lubao", "Mabalacat City", "Macabebe", "Magalang", "Masantol", "Mexico", "Minalin", "Porac", "San Fernando City", "San Luis", "San Simon", "Santa Ana", "Santa Rita", "Santo Tomas", "Sasmuan"],
        "Tarlac": ["Anao", "Bamban", "Camiling", "Capas", "Concepcion", "Gerona", "La Paz", "Mayantoc", "Moncada", "Paniqui", "Pura", "Ramos", "San Clemente", "San Jose", "San Manuel", "Santa Ignacia", "Tarlac City", "Victoria"],
        "Zambales": ["Botolan", "Cabangan", "Candelaria", "Castillejos", "Iba", "Masinloc", "Olongapo City", "Palauig", "San Antonio", "San Felipe", "San Marcelino", "San Narciso", "Santa Cruz", "Subic"]
    },
    "Region IV-A": {
        "Batangas": ["Agoncillo", "Alitagtag", "Balayan", "Balete", "Batangas City", "Bauan", "Calaca", "Calatagan", "Cuenca", "Ibaan", "Laurel", "Lemery", "Lian", "Lipa City", "Lobo", "Mabini", "Malvar", "Mataasnakahoy", "Nasugbu", "Padre Garcia", "Rosario", "San Jose", "San Juan", "San Luis", "San Nicolas", "San Pascual", "Santa Teresita", "Santo Tomas City", "Taal", "Talisay", "Tanauan City", "Taysan", "Tingloy", "Tuy"],
        "Cavite": ["Alfonso", "Amadeo", "Bacoor City", "Carmona", "Cavite City", "Dasmariñas City", "Gen. Emilio Aguinaldo", "Gen. Mariano Alvarez", "General Trias City", "Imus City", "Indang", "Kawit", "Magallanes", "Maragondon", "Mendez", "Naic", "Noveleta", "Rosario", "Silang", "Tagaytay City", "Tanza", "Ternate", "Trece Martires City"],
        "Laguna": ["Alaminos", "Bay", "Biñan City", "Cabuyao City", "Calamba City", "Calauan", "Cavinti", "Famy", "Kalayaan", "Liliw", "Los Baños", "Luisiana", "Lumban", "Mabitac", "Magdalena", "Majayjay", "Nagcarlan", "Paete", "Pagsanjan", "Pakil", "Pangil", "Pila", "Rizal", "San Pablo City", "San Pedro City", "Santa Cruz", "Santa Maria", "Santa Rosa City", "Siniloan", "Victoria"],
        "Quezon": ["Agdangan", "Alabat", "Atimonan", "Buenavista", "Burdeos", "Calauag", "Candelaria", "Catanauan", "Dolores", "General Luna", "General Nakar", "Guinayangan", "Gumaca", "Infanta", "Jomalig", "Lopez", "Lucban", "Lucena City", "Macalelon", "Mauban", "Mulanay", "Padre Burgos", "Pagbilao", "Panukulan", "Patnanungan", "Perez", "Pitogo", "Plaridel", "Polillo", "Quezon", "Real", "Sampaloc", "San Andres", "San Antonio", "San Francisco", "San Narciso", "Sariaya", "Tagkawayan", "Tayabas City", "Tiaong", "Unisan"],
        "Rizal": ["Angono", "Antipolo City", "Baras", "Binangonan", "Cainta", "Cardona", "Jalajala", "Morong", "Pililla", "Rodriguez", "San Mateo", "Tanay", "Taytay", "Teresa"]
    },
    "MIMAROPA": {
        "Marinduque": ["Boac", "Gasan", "Santa Cruz"],
        "Occidental Mindoro": ["Mamburao", "San Jose"],
        "Oriental Mindoro": ["Calapan", "Pinamalayan", "Naujan"],
        "Palawan": ["Puerto Princesa", "Coron", "El Nido", "Narra"],
        "Romblon": ["Romblon", "Odiongan"]
    },
    "Region V": {
        "Albay": ["Legazpi", "Ligao", "Tabaco", "Daraga", "Guinobatan"],
        "Camarines Norte": ["Daet", "Labo"],
        "Camarines Sur": ["Naga", "Iriga", "Pili", "Libmanan"],
        "Catanduanes": ["Virac"],
        "Masbate": ["Masbate City"],
        "Sorsogon": ["Sorsogon City"]
    },
    "Region VI": {
        "Aklan": ["Kalibo"],
        "Antique": ["San Jose de Buenavista"],
        "Capiz": ["Roxas City"],
        "Guimaras": ["Jordan"],
        "Iloilo": ["Iloilo City", "Passi", "Oton", "Pavia"],
        "Negros Occidental": ["Bacolod", "Bago", "Cadiz", "La Carlota", "Sagay", "San Carlos", "Silay", "Sipalay", "Talisay", "Victorias"]
    },
    "Region VII": {
        "Bohol": ["Tagbilaran"],
        "Cebu": ["Cebu City", "Lapu-Lapu", "Mandaue", "Talisay", "Danao", "Toledo", "Carcar", "Naga", "Bogo"],
        "Negros Oriental": ["Dumaguete", "Bais", "Bayawan", "Canlaon", "Guihulngan", "Tanjay"],
        "Siquijor": ["Siquijor"]
    },
    "Region VIII": {
        "Biliran": ["Naval"],
        "Eastern Samar": ["Borongan"],
        "Leyte": ["Tacloban", "Ormoc", "Baybay"],
        "Northern Samar": ["Catarman"],
        "Samar": ["Catbalogan", "Calbayog"],
        "Southern Leyte": ["Maasin"]
    },
    "Region IX": {
        "Zamboanga del Norte": ["Dipolog", "Dapitan"],
        "Zamboanga del Sur": ["Pagadian", "Zamboanga City"],
        "Zamboanga Sibugay": ["Ipil"]
    },
    "Region X": {
        "Bukidnon": ["Malaybalay", "Valencia"],
        "Camiguin": ["Mambajao"],
        "Lanao del Norte": ["Iligan"],
        "Misamis Occidental": ["Oroquieta", "Ozamiz", "Tangub"],
        "Misamis Oriental": ["Cagayan de Oro", "El Salvador", "Gingoog"]
    },
    "Region XI": {
        "Davao de Oro": ["Nabunturan"],
        "Davao del Norte": ["Tagum", "Panabo", "Samal"],
        "Davao del Sur": ["Davao City", "Digos"],
        "Davao Occidental": ["Malita"],
        "Davao Oriental": ["Mati"]
    },
    "Region XII": {
        "Cotabato": ["Kidapawan"],
        "Sarangani": ["Alabel"],
        "South Cotabato": ["Koronadal", "General Santos"],
        "Sultan Kudarat": ["Isulan", "Tacurong"]
    },
    "Region XIII": {
        "Agusan del Norte": ["Butuan", "Cabadbaran"],
        "Agusan del Sur": ["Prosperidad", "Bayugan"],
        "Dinagat Islands": ["San Jose"],
        "Surigao del Norte": ["Surigao City"],
        "Surigao del Sur": ["Tandag", "Bislig"]
    },
    "BARMM": {
        "Basilan": ["Isabela City", "Lamitan"],
        "Lanao del Sur": ["Marawi"],
        "Maguindanao": ["Buluan", "Cotabato City"],
        "Sulu": ["Jolo"],
        "Tawi-Tawi": ["Bongao"]
    }
};

const barangayData = {
    // NATIONAL CAPITAL REGION (NCR)
    "Quezon City": [
        "Alicia", "Amihan", "Apolonio Samson", "Aurora", "Baesa", "Bagbag", "Bagong Lipunan ng Crame", "Bagong Pag-asa", "Bagong Silangan", "Bagumbayan", "Bagumbuhay", "Bahay Toro", "Balingasa", "Balumbato", "Batasan Hills", "Bayanihan", "Blue Ridge A", "Blue Ridge B", "Botocan", "Bungad", "Camp Aguinaldo", "Capri", "Central", "Claro", "Commonwealth", "Crame", "Culiat", "Damar", "Damayan", "Damayan Lagi", "Del Monte", "Dioquino Zobel", "Doña Aurora", "Doña Imelda", "Doña Josefa", "Duyan-duyan", "E. Rodriguez", "East Kamias", "Escopa I", "Escopa II", "Escopa III", "Escopa IV", "Fairview", "Greater Fairview", "Greater Lagro", "Gulod", "Holy Spirit", "Horacio de la Costa", "Ilang-Ilang", "Kaligayahan", "Kalusugan", "Kamuning", "Katipunan", "Kaunlaran", "Kristong Hari", "Krus na Ligas", "Laging Handa", "Libis", "Lourdes", "Loyola Heights", "Maharlika", "Malaya", "Mangga", "Manresa", "Mariana", "Mariblo", "Masagana", "Masambong", "Matandang Balara", "Milagrosa", "Nagkaisang Nayon", "N.S. Amoranto", "Nayong Kanluran", "New Era", "North Fairview", "Novaliches Proper", "Obrero", "Paang Bundok", "Pag-ibig sa Nayon", "Paligsahan", "Paltok", "Pansol", "Paraiso", "Pasong Putik Proper", "Pasong Tamo", "Payatas", "Phil-Am", "Pinagkaisahan", "Pinyahan", "Project 6", "Quirino 2-A", "Quirino 2-B", "Quirino 2-C", "Quirino 3-A", "Ramon Magsaysay", "Roxas", "Sacred Heart", "Saint Peter", "Salvacion", "San Agustin", "San Antonio", "San Bartolome", "San Isidro", "San Isidro Labrador", "San Jose", "San Martin de Porres", "San Roque", "San Vicente", "Sangandaan", "Santa Cruz", "Santa Lucia", "Santa Monica", "Santa Teresita", "Santo Cristo", "Santo Domingo", "Santol", "Sauyo", "Siena", "Sikatuna Village", "Silangan", "Socorro", "South Triangle", "Tagumpay", "Talampas", "Talayan", "Talipapa", "Tandang Sora", "Tatalon", "Teachers Village East", "Teachers Village West", "U.P. Campus", "U.P. Village", "Valencia", "Vasra", "Veterans Village", "Villa Maria Clara", "West Kamias", "West Triangle", "White Plains"
    ],
    "Manila": [
        "Binondo", "Ermita", "Intramuros", "Malate", "Paco", "Pandacan", "Port Area", "Quiapo", "Sampaloc", "San Andres", "San Miguel", "San Nicolas", "Santa Ana", "Santa Cruz", "Santa Mesa", "Tondo I", "Tondo II"
    ].concat(Array.from({length: 897}, (_, i) => `Barangay ${i + 1}`)),
    "Caloocan": Array.from({length: 188}, (_, i) => `Barangay ${i + 1}`),
    "Taguig": [
        "Bagumbayan", "Bambang", "Calzada", "Central Lower Bicutan", "Central Signal Village", "Fort Bonifacio (BGC)", "Hagonoy", "Ibayo-Tipas", "Katuparan", "Ligid-Tipas", "Lower Bicutan", "Maharlika Village", "Napindan", "New Lower Bicutan", "North Daang Hari", "North Signal Village", "Palingon", "Pinagsama", "San Roque", "Santa Ana", "South Daang Hari", "South Signal Village", "Tanyag", "Tuktukan", "Upper Bicutan", "Ususan", "Wawa", "Western Bicutan"
    ],
    "Makati": [
        "Bangkal", "Bel-Air", "Carmona", "Cembo", "Comembo", "Dasmariñas Village", "Forbes Park", "Guadalupe Nuevo", "Guadalupe Viejo", "Kasilawan", "La Paz", "Magallanes Village", "Olimpia", "Palanan", "Pembo", "Pinagkaisahan", "Pio del Pilar", "Poblacion", "Post Proper Northside", "Post Proper Southside", "Rembo", "Rizal", "San Antonio", "San Isidro", "San Lorenzo", "Santa Cruz", "Singkamas", "South Cembo", "Tejeros", "Urdaneta Village", "Valenzuela", "West Rembo"
    ],
    "Pasig": [
        "Bagong Ilog", "Bagong Katipunan", "Bambang", "Buting", "Caniogan", "Dela Paz", "Kalawaan", "Kapasigan", "Kapitolyo", "Malinao", "Manggahan", "Maybunga", "Oranbo", "Palatiw", "Pinagbuhatan", "Pineda", "Rosario", "Sagad", "San Joaquin", "San Jose", "San Miguel", "San Nicolas", "Santa Cruz", "Santa Lucia", "Santa Rosa", "Santolan", "Sumilang", "Ugong"
    ],
    "Mandaluyong": [
        "Addition Hills", "Bagong Silang", "Barangka Drive", "Barangka Ibaba", "Barangka Ilaya", "Barangka Itaas", "Buayang Bato", "Burol", "Daang Bakal", "Hagdang Bato Itaas", "Hagdang Bato Libis", "Harapin Ang Bukas", "Highway Hills", "Hulo", "Mabini-J. Rizal", "Malamig", "Namayan", "New Zañiga", "Old Zañiga", "Pag-asa", "Plainview", "Pleasant Hills", "Poblacion", "San Jose", "Vergara", "Wack-Wack Greenhills"
    ],
    "Marikina": [
        "Concepcion Dos", "Concepcion Uno", "Fortune", "Industrial Valley Complex", "Jesus de la Peña", "Malanday", "Marikina Heights", "Nangka", "Parang", "San Roque", "Santa Elena", "Santo Niño", "Tañong", "Tumana"
    ],
    "Pasay": Array.from({length: 201}, (_, i) => `Barangay ${i + 1}`),
    "Parañaque": [
        "Baclaran", "Don Galo", "La Huerta", "San Dionisio", "Sto. Niño", "Tambo", "Vitalez", "B.F. Homes", "Don Bosco", "Marcelo Green", "Merville", "Moonwalk", "San Antonio", "San Martin de Porres", "Sun Valley"
    ],
    "Valenzuela": [
        "Arkong Bato", "Bagbaguin", "Balangkas", "Bignay", "Canumay East", "Canumay West", "Coloong", "Dalandanan", "Gen. T. de Leon", "Isla", "Karuhatan", "Lawang Bato", "Lingunan", "Mabolo", "Malanday", "Malinta", "Mapulang Lupa", "Marulas", "Maysan", "Palasan", "Pariancillo Villa", "Paso de Blas", "Pasolo", "Poblacion", "Pulo", "Punturin", "Rincon", "Tagalag", "Ugong", "Viente Reales", "Wawang Pulo"
    ],
    "Muntinlupa": [
        "Alabang", "Bayanan", "Buli", "Cupang", "Poblacion", "Putatan", "Sucat", "Tunasan", "Ayala Alabang"
    ],
    "Las Piñas": [
        "Almanza Uno", "Almanza Dos", "CAA-B.F. International", "Daniel Fajardo", "Elias Aldana", "Ilaya", "Manuyo Uno", "Manuyo Dos", "Pamplona Uno", "Pamplona Dos", "Pamplona Tres", "Pilar Village", "Pulang Lupa Uno", "Pulang Lupa Dos", "Zapote", "Talon Uno", "Talon Dos", "Talon Tres", "Talon Cuatro", "Talon V"
    ],
    "Malabon": [
        "Acacia", "Baritan", "Bayan-bayanan", "Catmon", "Concepcion", "Dampalit", "Flores", "Hulong Duhat", "Ibaba", "Longos", "Maysilo", "Muzon", "Niugan", "Panghulo", "Potrero", "San Agustin", "Santolan", "Tañong", "Tinajeros", "Tugatog", "Pulo"
    ],
    "Navotas": [
        "Bagumbayan North", "Bagumbayan South", "Bangkulasi", "Daanghari", "Navotas East", "Navotas West", "North Bay Boulevard North", "North Bay Boulevard South", "San Jose", "San Rafael Village", "San Roque", "Sipac-Almacen", "Tangos North", "Tangos South", "Tanza 1", "Tanza 2"
    ],
    "Pateros": [
        "Aguho", "Magtanggol", "Martirez del 96", "Poblacion", "San Pedro", "San Roque", "Santa Ana", "Santo Rosario-Kanluran", "Santo Rosario-Silangan", "Tabacalera"
    ],

    // REGION III (CENTRAL LUZON)
    "Malolos City": [
        "Anilao", "Atlag", "Babatnin", "Bagna", "Bagong Bayan", "Balayong", "Balite", "Bangkal", "Barihan", "Bulihan", "Bungahan", "Caingin", "Calero", "Caliligawan", "Canalate", "Caniogan", "Catmon", "Cofradia", "Dakila", "Guinhawa", "Liang", "Ligas", "Longos", "Look 1st", "Look 2nd", "Lugam", "Mabolo", "Mambog", "Masile", "Matimbo", "Mojon", "Namayan", "Niugan", "Pamarawan", "Panasahan", "Pinagbakahan", "San Agustin", "San Gabriel", "San Juan", "San Pablo", "San Vicente", "Santiago", "Santisima Trinidad", "Santo Cristo", "Santo Niño", "Santo Rosario", "Santor", "Sumapang Bata", "Sumapang Matanda", "Taal", "Tikay"
    ],
    "San Fernando City": [
        "Alasas", "Baliti", "Bulaon", "Calulut", "Del Carmen", "Del Pilar", "Del Rosario", "Dela Paz Norte", "Dela Paz Sur", "Dolores", "Juliana", "Lara", "Lourdes", "Magliman", "Maimpis", "Malino", "Malpitic", "Pandaras", "Panipuan", "Pulung Bulo", "Quebiawan", "Saguin", "San Agustin", "San Felipe", "San Isidro", "San Jose", "San Juan", "San Nicolas", "San Pedro Cutud", "Santa Lucia", "Santa Teresita", "Santo Niño", "Santo Rosario", "Sindalan", "Telabastagan"
    ],
    "Angeles City": [
        "Agapito del Rosario", "Amsic", "Anunas", "Balibago", "Capaya", "Claro M. Recto", "Cuayan", "Cutcut", "Cutud", "Lourdes North West", "Lourdes Sur", "Lourdes Sur East", "Malabañas", "Margot", "Marisol", "Mining", "Pampang", "Pandan", "Pulung Bulu", "Pulung Cacutud", "Pulung Maragul", "Salapungan", "San José", "San Nicolas", "Santa Teresita", "Santa Trinidad", "Santo Cristo", "Santo Domingo", "Santo Rosario", "Sapalibutad", "Sapangbato", "Tabun", "Virgen Delos Remedios"
    ],
    "Mabalacat City": [
        "Atlu-Bola", "Bical", "Bundagul", "Cacamilihan", "Calumpang", "Camachiles", "Dapdap", "Dau", "Dolores", "Duquit", "Lakandula", "Mabiga", "Macapagal Village", "Mamatitang", "Mangalit", "Marcos Village", "Mawaque", "Poblacion", "San Francisco", "San Joaquin", "Santa Ines", "Santa Maria", "Sapang Balen", "Sapang Biabas", "Tabun"
    ],

    // REGION IV-A (CALABARZON)
    "Batangas City": Array.from({length: 24}, (_, i) => `Barangay ${i + 1}`).concat([
        "Alangilan", "Balagtas", "Balete", "Banaba Center", "Banaba Ibaba", "Banaba Kanluran", "Banaba Silangan", "Bilogo", "Bolbok", "Bukal", "Calicanto", "Catandala", "Concepcion", "Conde Itaas", "Conde Labac", "Cumba", "Cuta", "Dalig", "Dela Paz Proper", "Dela Paz Pulot Aplaya", "Dumantay", "Dumuclay East", "Dumuclay West", "Guinto East", "Guinto West", "Gulod Itaas", "Gulod Labac", "Haligue Kanluran", "Haligue Silangan", "Ilijan", "Kumintang Ibaba", "Kumintang Ilaya", "Libjo", "Lilinggiwan", "Mabacong", "Mahabang Dalihig", "Mahacot", "Malalim", "Malibayo", "Malitam", "Maruclap", "Matoco", "Pagkilatan", "Paharang Kanluran", "Paharang Silangan", "Pallocan West", "Pallocan East", "Pinamucan Silangan", "Pinamucan Proper", "San Agapito", "San Antonio", "San Isidro", "San Jose Sico", "San Miguel", "Santa Clara", "Santa Rita Aplaya", "Santa Rita Karsada", "Santo Domingo", "Santo Niño", "Simlong", "Sirang Lupa", "Sorosoro Ilaya", "Sorosoro Karsada", "Talahib Pandayan", "Talahib Payapa", "Talumpok Silangan", "Talumpok Kanluran", "Tulo", "Wawa"
    ]),
    "Lipa City": Array.from({length: 12}, (_, i) => `Barangay ${i + 1}`).concat([
        "Adya", "Anilao", "Anilao-Labac", "Antipolo", "Bagong Pook", "Balintawak", "Banay-banay", "Bolbok", "Bugtong na Pulo", "Bulacnin", "Bulaklakan", "Calamias", "Cumba", "Dagatan", "Duhatan", "Fernando Air Base", "Halang", "Kayumanggi", "Latag", "Lodlod", "Lumbang", "Mabini", "Malagonlong", "Malitlit", "Marawoy", "Mataas na Lupa", "Munting Pulo", "Pag-olingin Bata", "Pag-olingin East", "Pag-olingin West", "Pangao", "Pinagkawitan", "Pinagtong-ulan", "Plaridel", "Poblacion", "Quezon", "Rillo", "Rizal", "Sampaguita", "San Benito", "San Carlos", "San Celestino", "San Francisco", "San Guillermo", "San Jose", "San Lucas", "San Salvador", "San Sebastian", "Santo Niño", "Santo Toribio", "Sico", "Talisay", "Tambo", "Tangway", "Tibig", "Tropa"
    ]),
    "Calamba City": [
        "Bagong Kalsada", "Banaba", "Banlic", "Barandal", "Batino", "Bañadero", "Bubuyan", "Bucal", "Bunggo", "Burol", "Cabulusan", "Canlubang", "Kapayapaan", "Kay-Anlog", "La Mesa", "Laguerta", "Lawa", "Lecheria", "Lingga", "Looc", "Mabato", "Majada Labas", "Makiling", "Mapagong", "Masili", "Mayapa", "Milagrosa", "Paciano Rizal", "Palingon", "Palo-Alto", "Pansol", "Parian", "Prinza", "Punta", "Real", "Saimsim", "Sampiruhan", "San Cristobal", "San Jose", "San Juan", "Sirang Lupa", "Sucol", "Tulo", "Uwisan"
    ].concat(Array.from({length: 5}, (_, i) => `Poblacion ${i + 1}`)),
    "Santa Rosa City": [
        "Aplaya", "Balibago", "Caingin", "Dila", "Dita", "Don Jose", "Ibaba", "Kanluran", "Labas", "Macabling", "Malitlit", "Malusak", "Market Area", "Pooc", "Pulong Santa Cruz", "Santo Domingo", "Sinalhan", "Tagapo"
    ],
    "Cabuyao City": [
        "Baclaran", "Banay-banay", "Banlic", "Bigaa", "Butong", "Casile", "Diezmo", "Gulod", "Mamatid", "Marinig", "Niugan", "Pittland", "Pulo", "Sala", "San Isidro", "Poblacion I", "Poblacion II", "Poblacion III"
    ],
    "Biñan City": [
        "Biñan", "Bungahan", "Canlalay", "Casile", "De La Paz", "Ganado", "Langkiwa", "Loma", "Malaban", "Malamig", "Mampalasan", "Platero", "Poblacion", "San Antonio", "San Francisco", "San Jose", "San Vicente", "Santo Niño", "Santo Tomas", "Soro-soro", "Timbao", "Zapote"
    ],
    "San Pedro City": [
        "Bagong Silang", "Chrysanthemum", "Cuyab", "Estrella", "Fatima", "G.S.I.S.", "Landayan", "Langgam", "Laram", "Magsaysay", "Maharlika", "Nueva", "Pacita I", "Pacita II", "Poblacion", "Riverside", "Sampaguita", "San Antonio", "San Lorenzo", "San Roque", "San Vicente", "Santo Niño", "United Bayanihan", "United Better Living"
    ],
    
    // Cavite Cities
    "Imus City": ["Anabu I/II", "Bayang Luma", "Bucandala I-V", "Carsadang Bago I/II", "Malagasang I/II", "Medicion I/II", "Poblacion", "Tanzang Luma"],
    "Dasmariñas City": ["Salitran I-IV", "Sampaloc I-V", "San Agustin I-III", "San Jose", "Salawag", "Paliparan I-III", "Burol I-III", "Langkaan I/II"],
    "Trece Martires City": ["De Ocampo", "Gregorio", "Inocencio", "Lallana", "Lapidario", "Luciano", "Osorio", "Perez", "San Agustin", "Cabezas", "Hugo Perez", "Conchu", "Aguado"],
    "General Trias City": ["Bacao I/II", "Buenavista I-III", "Manggahan", "Pasong Camachile I/II", "San Francisco", "Santa Clara", "Tejero"],
    "Tanza": ["Amaya 1", "Amaya 2", "Amaya 3", "Amaya 4", "Amaya 5", "Amaya 6", "Amaya 7", "Bagtas", "Biga", "Biwas", "Bucal", "Bunga", "Calibuyo", "Capipisa", "Daang Amaya 1", "Daang Amaya 2", "Daang Amaya 3", "Halayhay", "Julugan 1", "Julugan 2", "Julugan 3", "Julugan 4", "Julugan 5", "Julugan 6", "Julugan 7", "Julugan 8", "Lambingan", "Mulawin", "Paradahan 1", "Paradahan 2", "Poblacion 1", "Poblacion 2", "Poblacion 3", "Poblacion 4", "Punta 1", "Punta 2", "Sahud Ulan", "Sanja Mayor", "Santol", "Tanauan", "Tres Cruses"],
    "Kawit": ["Binakayan", "Gahak", "Kaingen", "Marulas", "Panamitan", "Poblacion", "Potol", "Putol", "San Sebastian", "Santa Isabel", "Tabon"],
    "Rosario": ["Bagbag", "Ligtong", "Muzon", "Poblacion", "Sapa", "Tejeros", "Wawa"],
    "Carmona": ["Bancal", "Cabilang Baybay", "Lantic", "Mabuhay", "Maduya", "Milagrosa", "Poblacion I-VIII"],
    "Silang": ["Biluso", "Bulihan", "Iba", "Lalaan I/II", "Lumil", "Munting Ilog", "Puting Kahoy", "San Vicente", "Tibig"],

    // REGION I & II (NORTHERN LUZON)
    "Dagupan City": ["Arellano-Bani", "Bacayao Norte", "Bacayao Sur", "Barangay I (T. Bugallon)", "Barangay II (Quezon Blvd.)", "Barangay IV (Zamora)", "Bolosan", "Bonuan Binloc", "Bonuan Boquig", "Bonuan Gueset", "Calmay", "Carael", "Caranglaan", "Herrero-Perez", "Lasip Chico", "Lasip Grande", "Lomboy", "Lucao", "Malued", "Mamalingling", "Mangin", "Mayombo", "Pantal", "Poblacion Oeste", "Pugo Chico", "Salapingao", "Salisay", "Tambac", "Tapuac", "Tebeng"],
    "Urdaneta City": ["Anonas", "Bactad Proper", "Bayaoas", "Bolaoen", "Cabuloan", "Camantiles", "Casantaan", "Catablan", "Cayambanan", "Consolacion", "Dilan-Paurido", "Labit Proper", "Labit West", "Mabanogbog", "Macalong", "Nancalobasaan", "Nancamaliran East", "Nancamaliran West", "Nancayasan", "Oltama", "Palina East", "Palina West", "Pinmaludpod", "Poblacion", "San Jose", "San Vicente", "Santa Lucia", "Santo Domingo", "Tipuso"],
    "Laoag City": Array.from({length: 80}, (_, i) => `Barangay ${i + 1}`).concat(["Poblacion"]),
    "Vigan City": ["Ayusan Norte", "Ayusan Sur", "Barangay I", "Barangay II", "Barangay III", "Barangay IV", "Barangay IX", "Barangay V", "Barangay VI", "Barangay VII", "Barangay VIII", "Barraca", "Beddeng Daya", "Beddeng Laud", "Bongtolan", "Bulala", "Cabaroan Daya", "Cabaroan Laud", "Camangaan", "Capangpangan", "Mindoro", "Nagsangalan", "Pantay Daya", "Pantay Laud", "Paoa", "Paratong", "Purok-a-bassit", "Purok-a-daco", "Raois", "Rugsuanan", "Salcedo", "San Julian Norte", "San Julian Sur", "San Pedro", "Tamag"],
    
    // REGION V (BICOL)
    "Naga City": ["Abella", "Bagumbayan Norte", "Bagumbayan Sur", "Balatas", "Calauag", "Cararayan", "Carolina", "Concepcion Grande", "Concepcion Pequeña", "Dayangdang", "Del Rosario", "Igualdad Interior", "Lerma", "Liboton", "Mabolo", "Pacol", "Panicuason", "Peñafrancia", "Sabang", "San Felipe", "San Francisco", "San Isidro", "Santa Cruz", "Tabuco", "Tinago", "Triangulo"],
    "Legazpi City": ["Albay District", "Arimbay", "Bagacay", "Bagumbayan", "Banquerohan", "Bariw", "Bigaa", "Binanuahan", "Bogchi", "Bondoc", "Buang", "Cabangan", "Capantawan", "Dap-dap", "Doña Maria", "Gogo", "Homapon", "Ilawod", "Imalnod", "Legazpi Port District", "Lidong", "Mabinit", "Maoyod", "Maslog", "Pawa", "Pigcale", "Rawis", "San Francisco", "San Joaquin", "San Roque", "Tula-tula"],

    // VISAYAS
    "Iloilo City": ["Arevalo District", "City Proper District", "Jaro District", "La Paz District", "Lapuz District", "Mandurriao District", "Molo District", "Bo. Obrero", "Calumpang", "Dulong Bayan", "Hibao-an", "Lanit", "Sambag", "Ungka"],
    "Bacolod City": Array.from({length: 61}, (_, i) => `Barangay ${i + 1}`).concat(["Alangilan", "Banago", "Bata", "Cabug", "Felisa", "Granada", "Handumanan", "Mandalagan", "Mansilingan", "Pahanocoy", "Punta Taytay", "Sum-ag", "Tangub", "Villamonte", "Vista Alegre"]),
    "Cebu City": ["Adlaon", "Apas", "Babag", "Bacayan", "Banilad", "Basak Pardo", "Basak San Nicolas", "Binaliw", "Bonbon", "Budlaan", "Buhisan", "Bulacao", "Buot-Taup", "Busay", "Calamba", "Cambinocot", "Capitol Site", "Carreta", "Cogon Pardo", "Cogon Ramos", "Day-as", "Duljo-Fatima", "Ermita", "Guadalupe", "Guba", "Hippodromo", "Inayawan", "Kalubihan", "Kalunasan", "Kamagayan", "Kamputhaw", "Kasambagan", "Kinasang-an", "Labangon", "Lahug", "Lorega San Miguel", "Lusaran", "Luz", "Mabini", "Mabolo", "Malubog", "Mambaling", "Pahina Central", "Pahina San Nicolas", "Pardo", "Pari-an", "Paril", "Pasil", "Pit-os", "Poblacion Pardo", "Pulangbato", "Pung-ol-Sibugay", "Punta Princesa", "Quiot", "Sambag I", "Sambag II", "San Antonio", "San Jose", "San Nicolas Proper", "San Roque", "Santa Cruz", "Sawang Calero", "Sinsin", "Sirao", "Suba", "Tabunan", "Tagbao", "Talamban", "Taptap", "Tejero", "Tinago", "Tisa", "Tolongot", "Zapatera"],

    // MINDANAO
    "Davao City": ["Agdao District", "Buhangin District", "Bunawan District", "Calinan District", "Marilog District", "Paquibato District", "Poblacion District", "Talomo District", "Toril District", "Tugbok District", "Acacia", "Bago Aplaya", "Bago Gallera", "Bago Oshiro", "Bucana", "Cabantian", "Catalunan Grande", "Catalunan Pequeño", "Indangan", "Ma-a", "Matina Crossing", "Matina Pangi", "Matina Aplaya", "Mintal", "Sasa", "Tigatto"],
    "General Santos City": ["Apopong", "Baluan", "Bula", "Calumpang", "City Heights", "Conel", "Dadiangas East", "Dadiangas North", "Dadiangas South", "Dadiangas West", "Katangawan", "Labangal", "Lagao", "Mabuhay", "Olympog", "Poblacion", "San Isidro", "San Jose", "Siguel", "Sinawal", "Tambler", "Tinagacan", "Upper Labay"],
    "Zamboanga City": ["Abong-Abong", "Arena Blanco", "Ayala", "Baliwasan", "Bancao", "Boalan", "Calarian", "Canelar", "Guiwan", "Kasanyangan", "Lumbangan", "Lunzuran", "Mampang", "Pasonanca", "Puanani", "Putik", "San Jose Cawa-Cawa", "San Jose Gusu", "San Roque", "Santa Barbara", "Santa Catalina", "Santa Maria", "Talon-Talon", "Tetuan", "Tumaga", "Zambowood"]
};

const defaultBarangays = ["Poblacion", "San Jose", "San Isidro", "Santa Maria", "Santo Niño", "San Vicente", "Barangay 1", "Barangay 2", "Barangay 3"];

document.addEventListener('DOMContentLoaded', function() {
    const regionSelect = document.querySelector('select[name="region"]');
    const citySelect = document.querySelector('select[name="city"]');
    const barangaySelect = document.querySelector('select[name="barangay"]');

    if (!regionSelect || !citySelect || !barangaySelect) return;

    // Store initial values if any (e.g. from server-side render or previous session)
    const initialCity = citySelect.getAttribute('data-selected') || citySelect.value;
    const initialBarangay = barangaySelect.getAttribute('data-selected') || barangaySelect.value;

    regionSelect.addEventListener('change', function() {
        const region = this.value;
        const currentSelectedCity = citySelect.value;
        
        citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
        barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
        
        if (addressData[region]) {
            Object.keys(addressData[region]).sort().forEach(province => {
                const optGroup = document.createElement('optgroup');
                optGroup.label = province;
                
                addressData[region][province].sort().forEach(city => {
                    const option = document.createElement('option');
                    option.value = city;
                    option.textContent = city;
                    if (city === initialCity) option.selected = true;
                    optGroup.appendChild(option);
                });
                citySelect.appendChild(optGroup);
            });
        }

        // Trigger city change if city was auto-selected
        if (citySelect.value) {
            citySelect.dispatchEvent(new Event('change'));
        }
    });

    citySelect.addEventListener('change', function() {
        const city = this.value;
        barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
        
        if (city) {
            const barangays = barangayData[city] || defaultBarangays;
            
            barangays.sort((a, b) => {
                const aNum = parseInt(a.replace(/\D/g, ''));
                const bNum = parseInt(b.replace(/\D/g, ''));
                if (!isNaN(aNum) && !isNaN(bNum)) return aNum - bNum;
                return a.localeCompare(b);
            }).forEach(brgy => {
                const option = document.createElement('option');
                option.value = brgy;
                option.textContent = brgy;
                if (brgy === initialBarangay) option.selected = true;
                barangaySelect.appendChild(option);
            });
        }
    });

    // Initial population
    if (regionSelect.value) {
        regionSelect.dispatchEvent(new Event('change'));
    }
});
