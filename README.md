#Docker Bundle
Idea je taková, že Rkovej transformační backend bude napojenej na generickej `Docker Bundle`, jen mu UI předchroustá spoustu informací a TAPI ho zapojí do procesu. 

Zařazování Docker image do KBC je popsaný v Apiary: [http://docs.kebooladocker.apiary.io/]. Zadávání parametrů bude cca shodný s tím, co dělá Doctool. Tohle API bude přístupní jen administrátorům (dočasně zase bucket v **Shared configu**?). 

Pro R-kovej backend by mohl ten call na spuštění jobu vypadat třeba takhle:

```
{
    "config": "keboola-r",
    "data": 
        {
            "input": [
                {
                    "name": "orders", 
                    "value": "in.c-main.orders"
                }, 
                {
                    "name": "customers", 
                    "value": "in.c-main.customers"
                }
            ], 
            "parameters": {
            	"values": [],
                "script": "install.packages(\"fooBar\")\norders <- read.csv(\"in/orders.csv\")\ncustomers <- read.csv(\"in/customers.csv\")\n...\nwrite.table(nextorder, \"out/next-order.csv\")"

            }, 
            "output": [
                {
                    "name": "next-order", 
                    "value": "out.c-main.next-order"
                }
            ]
        }                
    }
}
```

Možná už je z toho patrný, co to cca dělá. 

V každém Docker image bude povinně skript `/run.sh`, který se jako jediný spustí. Docker bundle připravý soubor `/manifest.yml`, do kterého nasype konfiguraci, kterou jsme mu předali v API, tj.:

```
token: 235-######
config: 
  input:
    - { name: orders, value: in.c-main.orders }
    - { name: customers, value: in.c-main.customers }
  output:
    - { name: next-order, calue: out.c-main.next-order }
  parameters:
    values: []
```

a vytvoří ještě `/script`, který bude obsahovat obsah property script, tj.:

```
install.packages("fooBar")
orders <- read.csv("in/orders.csv")
customers <- read.csv("in/customers.csv")
...
write.table(nextorder, "out/next-order.csv")
```

`/run.sh` tuhle konfiguraci musí manifest načíst, zpracovat, spustit a vypnout se. TAPI tomu image nebude pomáhat ani s input/output mappingem, jenom pomůže v UI získat a předat parametry. Input/output mapping se bude řešit pomocí `storage-api-cli` (to bude nutné aktualizovat a doplnit tam třeba eventy). `/run.sh` se ukončí s exit kódem, který určí stav jobu. Celý výpis do příkazového řádku se pak vezme a vloží do eventu.

`/run.sh` pro Rkovej Docker image teda načte věcí z inputu, uloží je do `in/*.csv`, spustí commandy, co jsou uložený v souboru `/script` a po ukončení vezme výstuphí CSVčka z `out/*.csv` a pošle je do SAPI. 

Ostatní bundly budou moct bejt bez toho `script` parametru a to znamená, že budou zamčený (budou se parametrizovat přes `values`, pokud to bude potřeba). Tj. bude v nich natvrdo zapečená funkcionalita a bude možný jí zkonfigurovat vstupy, výstupy a parametry přes API viz Apiary. 

## Otazníky
- Motá se mi názvosloví, configuration, data, parameters, config, napříč bundlem i předávanejma konfiguracema. 
- Moc nevím, jak to odbavit v bashi, ale na to s Padákovou pomocí snad přijdem
- Co syslog? Jde nějak forwardovat ven?
- Jak nějaký podrobnější ladění chyb? Uživatelské vs aplikační? Dokážem na to zneužít exit status?
- Limitováni dockeru - to by mělo bejt asi součástí konfigurace toho image v API, měli bychom limitovat běh, paměť, io operace, prý přes `ulimit`/přímo přes Docker?
  - [http://stackoverflow.com/questions/16084741/how-do-i-set-resources-allocated-to-a-container-using-docker]
  - [https://goldmann.pl/blog/2014/09/11/resource-management-in-docker/#_limiting_read_write_speed]
- Trošku se mi stírá mezera mezi tím, co by mělo bejt čistě v Syrupu a co v Dockeru, jestli neabstrahujeme trochu divně. Chtěl bych vyjmout remote transformace ven do samostetnejch SAPI Komponent a třeba **text-splitter** by klidně mohl bejt Docker image, ale něco, co pracuje s SQL (rekonstrukce hierarchie v **hierarchy**) by si muselo složitě initovat spojení do DB a posílat tam spoustu commandů - tak to by asi byl samostatnej bundle. 
 
## Ukládání konfigurací

Docker příklad

Seznam komponent

/components/ex-db/
- url: https://syrup.keboola.com/docker/ex-db - 
- vlastní pole pro konfigurační JSON - limity, image, skript, konfigurace formuláře
- typ dle typu komponenty (extraktor, recept) 
- přepínač na 3rd party flag

/components/ex-db/configs

Spuštění

https://syrup.keboola.com/docker/ex-db/run
- načte si konfiguraci přes configId z /components/ex-db/configs
- načte si konfiguraci image z /configs/ex-db

Transformace - jedna konfigurace = celej bucket
