Tutorial - MySQL Double Buffering
---------------------------------

This project was created to test/demonstrate that one can:

1. Create a set of "buffer" tables that are linked to each other with foreign keys.
2. Import a large amount of data into the buffer tables
3. Rename the buffer tables to take over the "primary" tables, maintaining the foreign key relationships between the tables.

It is important that step 3 takes near to no time to complete, otherwise ingesting to a buffer is just extra effort.


## Running

Copy the `.env.example` to `.env` and adjust the values if you need to (you don't if you use Docker):

```bash
cp .env.example .env
```

Install the PHP packages with composer.

```bash
composer Install
```

Build the Docker Image

```bash
docker-compose build
```

Run the logic
```bash
docker-compose up
```

You will now have the output of the products and substitutions tables in local CSV files.

## References
* [EDUCBA -Postgres Rename Table](https://www.educba.com/postgres-rename-table/)
