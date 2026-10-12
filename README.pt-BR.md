# geo-frete

[![tests](https://github.com/FabioAugustoRodrigues/geo-frete/actions/workflows/tests.yml/badge.svg)](https://github.com/FabioAugustoRodrigues/geo-frete/actions/workflows/tests.yml)

*[Read in English](README.md)*

API REST open-source que calcula a **distância real de rota** entre dois endereços
brasileiros, construída sobre o framework PHP [iMono](https://github.com/FabioAugustoRodrigues/iMono).
Projeto de estudos, com piloto em Limeira/SP.

A API não conhece preço, cliente nem pedido: ela informa a distância, o tempo de viagem
e a precisão com que cada endereço foi localizado. Regra de negócio é responsabilidade
de quem consome.

## Como funciona

```
endereço digitado -> correção do nome da rua (base de ruas do CNEFE)
                  -> número da casa -> coordenadas (CNEFE; Nominatim como reserva)
                  -> rota pelas ruas no OSRM -> distância em km + tempo de viagem
                     (se o OSRM cair, usa a estimativa por linha reta)
```

- **CNEFE** (Cadastro Nacional de Endereços para Fins Estatísticos) é a lista de
  endereços do Censo 2022, publicada pelo IBGE com a coordenada coletada em campo para
  cada domicílio. Só Limeira tem 92.588 números de casa em 3.013 ruas. Números que
  faltam são interpolados entre os vizinhos do mesmo lado da rua.
- **Nominatim** geocodifica com dados do OpenStreetMap. No Brasil, poucos números de
  casa estão mapeados, então muitas vezes ele só acha a rua: é a reserva para o que o
  CNEFE não tem.
- **OSRM** (Open Source Routing Machine) calcula a rota mais rápida sobre o grafo de
  ruas do OpenStreetMap, em milissegundos, a partir de um mapa pré-processado uma vez.

## Resultados

Um atalho comum para calcular frete é "distância em linha reta × 1,3". Medido em 299
pares aleatórios de endereços reais de Limeira, com rota de carro
(`bin/compare-distances.php SP Limeira 300 42`):

| | mín | mediana | média | p90 | máx |
|---|---|---|---|---|---|
| rota / linha reta | 1,09 | 1,46 | 1,51 | 1,81 | 3,50 |

- A estimativa ×1,3 erra **11,5% na mediana** e 28% no percentil 90.
- Só **43,5%** dos pares ficam a até 10% da rota real.
- No pior caso, a estimativa fica **63% abaixo** da rota. Rios, rodovias e ruas sem
  saída fazem algumas rotas chegarem a 3,5× a linha reta; nenhum fator fixo absorve isso.
- O melhor fator único para a cidade seria 1,49, e ainda assim erraria os casos
  extremos. Daí o roteamento real.

A geocodificação pesa tanto quanto: para um endereço, o Nominatim devolveu "o meio da
rua", a 680 m do número de casa que o CNEFE conhece.

## API

Resumo navegável em `http://localhost:8080/`; referência completa (em inglês) em
[docs/API.md](docs/API.md).

| Endpoint | |
|---|---|
| `POST /api/v1/distance` | distância de rota e tempo de viagem entre dois endereços |
| `GET /api/v1/streets/suggestions` | autocomplete de nome de rua pela base local |
| `GET /api/v1/health` | estado do banco e do motor de rotas |

## Rodando localmente

Requisitos: PHP 8.1+ com `curl`, `pdo_mysql`, `mbstring` e `zip`, Composer e Docker.

```bash
composer install
cp .env.example .env              # preencha APP_CONTACT com um e-mail real (política do Nominatim)
docker compose up -d --build
curl http://localhost:8080/api/v1/health
```

A API responde em `http://localhost:8080`; o MySQL fica na porta `3307` do host.

O schema só é aplicado automaticamente quando o volume do MySQL é criado. Depois de
mudanças no schema, aplique num volume existente com:

```bash
docker compose exec -T mysql mysql -ugeo_frete -pgeo_frete geo_frete < database/schema.sql
```

> No Windows, o compartilhamento de arquivos do Docker deixa cada requisição ~0,4 s
> mais lenta. Para iterar rápido, rode `php -S localhost:8090 index.php` usando os
> mesmos containers.

### Base de endereços (CNEFE)

Importe cada cidade atendida (rodar de novo substitui a importação anterior):

```bash
docker compose exec api php bin/import-cnefe.php SP Limeira
```

O script descobre o código IBGE do município, baixa o arquivo do CNEFE (alguns MB,
guardados em `data/cnefe/`), junta os apartamentos de um mesmo número e carrega ruas e
números. Limeira leva cerca de 10 segundos. Sem importação a cidade continua
funcionando, só pelo Nominatim e sem correção de nomes de rua.

> Rodar com o PHP do host (`php bin/import-cnefe.php ...`) exige certificados HTTPS
> configurados; o XAMPP vem sem eles (configure `curl.cainfo` no `php.ini`).

### Mapa de rotas (OSRM)

Sem mapa, o container `osrm` encerra e a API responde com estimativas por linha reta
(`"method": "estimate"`). Para montar o mapa da cidade piloto:

```bash
docker compose run --rm map-clip    # baixa o mapa do Sudeste (~860 MB) e recorta Limeira
docker compose run --rm map-build   # pré-processamento do OSRM (~20 s)
docker compose restart osrm
```

Nas próximas vezes o download só acontece se o Geofabrik tiver publicado um mapa
novo, então repetir os três comandos (por exemplo, todo mês) atualiza o mapa. Outra
cidade: defina `OSRM_REGION_URL`, `OSRM_BBOX` (`lng_min,lat_min,lng_max,lat_max`) e
`OSRM_MAP_NAME` nas duas etapas. Os arquivos ficam em `osrm-data/` (fora do Git); o
download regional em `osrm-data/source/` pode ser apagado para liberar espaço.

### Reproduzindo os resultados

Com a cidade importada e o mapa montado:

```bash
docker compose exec api php bin/compare-distances.php SP Limeira 300 42
```

Argumentos: estado, cidade, quantidade de pares e semente aleatória (mesma semente,
mesmos pares).

## Configuração

As variáveis estão descritas, em inglês, no [README principal](README.md#configuration)
e no [.env.example](.env.example).

## Testes

```bash
vendor/bin/phpunit
```

Os testes unitários não precisam de nada. Os de integração e de feature usam o banco
`geo_frete_test` criado pelo Docker Compose, e são pulados quando ele não está
acessível. O CI roda a suíte inteira no PHP 8.1, 8.2 e 8.3 com MySQL 8.4.

## Dados e licenças

- Endereços: CNEFE, Censo Demográfico 2022, © [IBGE](https://www.ibge.gov.br/).
- Dados de mapa © contribuidores do [OpenStreetMap](https://www.openstreetmap.org/copyright),
  sob a Open Database License (ODbL), baixados do [Geofabrik](https://download.geofabrik.de/).

Código: [MIT](LICENSE).
