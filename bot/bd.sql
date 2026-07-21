--
-- PostgreSQL database dump
--

-- Dumped from database version 17.5
-- Dumped by pg_dump version 17.5

-- Started on 2026-02-05 00:40:53

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- TOC entry 230 (class 1259 OID 16764)
-- Name: bike_prices; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.bike_prices (
    id integer NOT NULL,
    bike_id integer,
    season_id integer,
    days_type character varying(255),
    price_per_day integer
);


ALTER TABLE public.bike_prices OWNER TO postgres;

--
-- TOC entry 229 (class 1259 OID 16763)
-- Name: bike_prices_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.bike_prices_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.bike_prices_id_seq OWNER TO postgres;

--
-- TOC entry 4903 (class 0 OID 0)
-- Dependencies: 229
-- Name: bike_prices_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.bike_prices_id_seq OWNED BY public.bike_prices.id;


--
-- TOC entry 226 (class 1259 OID 16736)
-- Name: bikes; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.bikes (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    category_id integer,
    description text,
    meta jsonb DEFAULT '{}'::jsonb,
    emoji character varying(255)
);


ALTER TABLE public.bikes OWNER TO postgres;

--
-- TOC entry 225 (class 1259 OID 16735)
-- Name: bikes_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.bikes_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.bikes_id_seq OWNER TO postgres;

--
-- TOC entry 4904 (class 0 OID 0)
-- Dependencies: 225
-- Name: bikes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.bikes_id_seq OWNED BY public.bikes.id;


--
-- TOC entry 224 (class 1259 OID 16729)
-- Name: categories; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.categories (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    description character varying(255)
);


ALTER TABLE public.categories OWNER TO postgres;

--
-- TOC entry 223 (class 1259 OID 16728)
-- Name: categories_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.categories_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.categories_id_seq OWNER TO postgres;

--
-- TOC entry 4905 (class 0 OID 0)
-- Dependencies: 223
-- Name: categories_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.categories_id_seq OWNED BY public.categories.id;


--
-- TOC entry 218 (class 1259 OID 16390)
-- Name: knex_migrations; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.knex_migrations (
    id integer NOT NULL,
    name character varying(255),
    batch integer,
    migration_time timestamp with time zone
);


ALTER TABLE public.knex_migrations OWNER TO postgres;

--
-- TOC entry 217 (class 1259 OID 16389)
-- Name: knex_migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.knex_migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.knex_migrations_id_seq OWNER TO postgres;

--
-- TOC entry 4906 (class 0 OID 0)
-- Dependencies: 217
-- Name: knex_migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.knex_migrations_id_seq OWNED BY public.knex_migrations.id;


--
-- TOC entry 220 (class 1259 OID 16397)
-- Name: knex_migrations_lock; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.knex_migrations_lock (
    index integer NOT NULL,
    is_locked integer
);


ALTER TABLE public.knex_migrations_lock OWNER TO postgres;

--
-- TOC entry 219 (class 1259 OID 16396)
-- Name: knex_migrations_lock_index_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.knex_migrations_lock_index_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.knex_migrations_lock_index_seq OWNER TO postgres;

--
-- TOC entry 4907 (class 0 OID 0)
-- Dependencies: 219
-- Name: knex_migrations_lock_index_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.knex_migrations_lock_index_seq OWNED BY public.knex_migrations_lock.index;


--
-- TOC entry 235 (class 1259 OID 96426)
-- Name: no_availability_requests; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.no_availability_requests (
    id integer NOT NULL,
    user_id integer,
    start_date date NOT NULL,
    end_date date NOT NULL,
    category_id integer,
    comment text,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.no_availability_requests OWNER TO postgres;

--
-- TOC entry 234 (class 1259 OID 96425)
-- Name: no_availability_requests_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.no_availability_requests_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.no_availability_requests_id_seq OWNER TO postgres;

--
-- TOC entry 4908 (class 0 OID 0)
-- Dependencies: 234
-- Name: no_availability_requests_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.no_availability_requests_id_seq OWNED BY public.no_availability_requests.id;


--
-- TOC entry 237 (class 1259 OID 96442)
-- Name: reminders; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.reminders (
    id integer NOT NULL,
    rental_id integer,
    type character varying(255) NOT NULL,
    send_at timestamp with time zone NOT NULL,
    sent boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.reminders OWNER TO postgres;

--
-- TOC entry 236 (class 1259 OID 96441)
-- Name: reminders_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.reminders_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.reminders_id_seq OWNER TO postgres;

--
-- TOC entry 4909 (class 0 OID 0)
-- Dependencies: 236
-- Name: reminders_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.reminders_id_seq OWNED BY public.reminders.id;


--
-- TOC entry 232 (class 1259 OID 16781)
-- Name: rentals; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.rentals (
    id integer NOT NULL,
    user_id integer,
    bike_id integer,
    start_date date NOT NULL,
    end_date date NOT NULL,
    total_price integer,
    status character varying(255) DEFAULT 'process'::character varying,
    comment text,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    confirmed_at timestamp with time zone,
    accept_terms boolean DEFAULT false NOT NULL,
    start_at timestamp with time zone,
    end_at timestamp with time zone,
    helmets_qty integer DEFAULT 0 NOT NULL,
    delivery_required boolean DEFAULT false NOT NULL,
    delivery_address text,
    docs_missing boolean DEFAULT false NOT NULL,
    booking_public_id character varying(255),
    deposit integer,
    deposit_paid boolean DEFAULT false NOT NULL,
    deposit_required integer,
    contract_file_id character varying(255),
    updated_at timestamp with time zone,
    deposit_note text
);


ALTER TABLE public.rentals OWNER TO postgres;

--
-- TOC entry 231 (class 1259 OID 16780)
-- Name: rentals_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.rentals_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.rentals_id_seq OWNER TO postgres;

--
-- TOC entry 4910 (class 0 OID 0)
-- Dependencies: 231
-- Name: rentals_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.rentals_id_seq OWNED BY public.rentals.id;


--
-- TOC entry 228 (class 1259 OID 16753)
-- Name: seasons; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.seasons (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    months integer[]
);


ALTER TABLE public.seasons OWNER TO postgres;

--
-- TOC entry 227 (class 1259 OID 16752)
-- Name: seasons_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.seasons_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.seasons_id_seq OWNER TO postgres;

--
-- TOC entry 4911 (class 0 OID 0)
-- Dependencies: 227
-- Name: seasons_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.seasons_id_seq OWNED BY public.seasons.id;


--
-- TOC entry 233 (class 1259 OID 16802)
-- Name: sessions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.sessions (
    user_id bigint NOT NULL,
    data jsonb NOT NULL
);


ALTER TABLE public.sessions OWNER TO postgres;

--
-- TOC entry 222 (class 1259 OID 16715)
-- Name: users; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.users (
    id integer NOT NULL,
    telegram_id bigint NOT NULL,
    telegram_name character varying(255),
    name character varying(255),
    phone character varying(255),
    passport_photo_file_id character varying(255),
    is_admin boolean DEFAULT false,
    meta jsonb DEFAULT '{}'::jsonb,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    lang character varying(255) DEFAULT 'ru'::character varying
);


ALTER TABLE public.users OWNER TO postgres;

--
-- TOC entry 221 (class 1259 OID 16714)
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.users_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.users_id_seq OWNER TO postgres;

--
-- TOC entry 4912 (class 0 OID 0)
-- Dependencies: 221
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- TOC entry 4685 (class 2604 OID 16767)
-- Name: bike_prices id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.bike_prices ALTER COLUMN id SET DEFAULT nextval('public.bike_prices_id_seq'::regclass);


--
-- TOC entry 4682 (class 2604 OID 16739)
-- Name: bikes id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.bikes ALTER COLUMN id SET DEFAULT nextval('public.bikes_id_seq'::regclass);


--
-- TOC entry 4681 (class 2604 OID 16732)
-- Name: categories id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.categories ALTER COLUMN id SET DEFAULT nextval('public.categories_id_seq'::regclass);


--
-- TOC entry 4674 (class 2604 OID 16393)
-- Name: knex_migrations id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.knex_migrations ALTER COLUMN id SET DEFAULT nextval('public.knex_migrations_id_seq'::regclass);


--
-- TOC entry 4675 (class 2604 OID 16400)
-- Name: knex_migrations_lock index; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.knex_migrations_lock ALTER COLUMN index SET DEFAULT nextval('public.knex_migrations_lock_index_seq'::regclass);


--
-- TOC entry 4694 (class 2604 OID 96429)
-- Name: no_availability_requests id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.no_availability_requests ALTER COLUMN id SET DEFAULT nextval('public.no_availability_requests_id_seq'::regclass);


--
-- TOC entry 4696 (class 2604 OID 96445)
-- Name: reminders id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.reminders ALTER COLUMN id SET DEFAULT nextval('public.reminders_id_seq'::regclass);


--
-- TOC entry 4686 (class 2604 OID 16784)
-- Name: rentals id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.rentals ALTER COLUMN id SET DEFAULT nextval('public.rentals_id_seq'::regclass);


--
-- TOC entry 4684 (class 2604 OID 16756)
-- Name: seasons id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.seasons ALTER COLUMN id SET DEFAULT nextval('public.seasons_id_seq'::regclass);


--
-- TOC entry 4676 (class 2604 OID 16718)
-- Name: users id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- TOC entry 4890 (class 0 OID 16764)
-- Dependencies: 230
-- Data for Name: bike_prices; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.bike_prices (id, bike_id, season_id, days_type, price_per_day) FROM stdin;
1	1	3	1d	250
2	1	3	7d	243
3	1	3	14d	214
4	1	3	21d	190
5	1	3	month	150
6	1	2	1d	250
7	1	2	7d	200
8	1	2	14d	186
9	1	2	21d	162
10	1	2	month	130
11	1	1	1d	200
12	1	1	7d	171
13	1	1	14d	161
14	1	1	21d	142
15	1	1	month	117
16	2	3	1d	300
17	2	3	7d	271
18	2	3	14d	236
19	2	3	21d	210
20	2	3	month	173
21	2	2	1d	250
22	2	2	7d	229
23	2	2	14d	214
24	2	2	21d	200
25	2	2	month	160
26	2	1	1d	250
27	2	1	7d	186
28	2	1	14d	171
29	2	1	21d	167
30	2	1	month	133
31	3	3	1d	330
32	3	3	7d	300
33	3	3	14d	271
34	3	3	21d	240
35	3	3	month	200
36	3	2	1d	300
37	3	2	7d	250
38	3	2	14d	200
39	3	2	21d	219
40	3	2	month	175
41	3	1	1d	250
42	3	1	7d	200
43	3	1	14d	179
44	3	1	21d	185
45	3	1	month	150
46	4	3	1d	330
47	4	3	7d	300
48	4	3	14d	271
49	4	3	21d	240
50	4	3	month	200
51	4	2	1d	300
52	4	2	7d	250
53	4	2	14d	200
54	4	2	21d	219
55	4	2	month	175
56	4	1	1d	250
57	4	1	7d	200
58	4	1	14d	179
59	4	1	21d	185
60	4	1	month	150
61	5	3	1d	330
62	5	3	7d	300
63	5	3	14d	271
64	5	3	21d	240
65	5	3	month	200
66	5	2	1d	300
67	5	2	7d	250
68	5	2	14d	200
69	5	2	21d	219
70	5	2	month	175
71	5	1	1d	250
72	5	1	7d	200
73	5	1	14d	179
74	5	1	21d	185
75	5	1	month	150
76	7	3	1d	350
77	7	3	7d	314
78	7	3	14d	300
79	7	3	21d	300
80	7	3	month	233
81	7	2	1d	330
82	7	2	7d	286
83	7	2	14d	264
84	7	2	21d	242
85	7	2	month	200
86	7	1	1d	300
87	7	1	7d	257
88	7	1	14d	236
89	7	1	21d	228
90	7	1	month	183
91	8	3	1d	350
92	8	3	7d	314
93	8	3	14d	300
94	8	3	21d	300
95	8	3	month	233
96	8	2	1d	330
97	8	2	7d	286
98	8	2	14d	264
99	8	2	21d	247
100	8	2	month	200
101	8	1	1d	300
102	8	1	7d	257
103	8	1	14d	236
104	8	1	21d	228
105	8	1	month	183
106	10	3	1d	350
107	10	3	7d	314
108	10	3	14d	300
109	10	3	21d	300
110	10	3	month	233
111	10	2	1d	330
112	10	2	7d	286
113	10	2	14d	264
114	10	2	21d	247
115	10	2	month	200
116	10	1	1d	300
117	10	1	7d	257
118	10	1	14d	236
119	10	1	21d	228
120	10	1	month	183
121	9	3	1d	350
122	9	3	7d	314
123	9	3	14d	300
124	9	3	21d	300
125	9	3	month	233
126	9	2	1d	330
127	9	2	7d	286
128	9	2	14d	264
129	9	2	21d	247
130	9	2	month	200
131	9	1	1d	300
132	9	1	7d	257
133	9	1	14d	236
134	9	1	21d	228
135	9	1	month	183
136	11	3	1d	370
137	11	3	7d	343
138	11	3	14d	314
139	11	3	21d	320
140	11	3	month	250
141	11	2	1d	350
142	11	2	7d	314
143	11	2	14d	300
144	11	2	21d	247
145	11	2	month	217
146	11	1	1d	330
147	11	1	7d	286
148	11	1	14d	257
149	11	1	21d	233
150	11	1	month	190
151	12	3	1d	400
152	12	3	7d	357
153	12	3	14d	350
154	12	3	21d	333
155	12	3	month	263
156	12	2	1d	370
157	12	2	7d	343
158	12	2	14d	321
159	12	2	21d	285
160	12	2	month	230
161	12	1	1d	330
162	12	1	7d	314
163	12	1	14d	271
164	12	1	21d	262
165	12	1	month	210
166	13	3	1d	450
167	13	3	7d	400
168	13	3	14d	350
169	13	3	21d	333
170	13	3	month	267
171	13	2	1d	370
172	13	2	7d	357
173	13	2	14d	343
174	13	2	21d	290
175	13	2	month	233
176	13	1	1d	350
177	13	1	7d	343
178	13	1	14d	286
179	13	1	21d	267
180	13	1	month	216
181	14	3	1d	850
182	14	3	7d	786
183	14	3	14d	714
184	14	3	21d	690
185	14	3	month	550
186	14	2	1d	750
187	14	2	7d	750
188	14	2	14d	643
189	14	2	21d	619
190	14	2	month	500
191	14	1	1d	700
192	14	1	7d	700
193	14	1	14d	571
194	14	1	21d	547
195	14	1	month	450
196	15	3	1d	900
197	15	3	7d	857
198	15	3	14d	800
199	15	3	21d	738
200	15	3	month	600
201	15	2	1d	800
202	15	2	7d	800
203	15	2	14d	714
204	15	2	21d	685
205	15	2	month	550
206	15	1	1d	750
207	15	1	7d	714
208	15	1	14d	643
209	15	1	21d	571
210	15	1	month	467
211	16	3	1d	1000
212	16	3	7d	943
213	16	3	14d	900
214	16	3	21d	809
215	16	3	month	650
216	16	2	1d	900
217	16	2	7d	900
218	16	2	14d	850
219	16	2	21d	742
220	16	2	month	600
221	16	1	1d	800
222	16	1	7d	857
223	16	1	14d	714
224	16	1	21d	685
225	16	1	month	550
\.


--
-- TOC entry 4886 (class 0 OID 16736)
-- Dependencies: 226
-- Data for Name: bikes; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.bikes (id, name, category_id, description, meta, emoji) FROM stdin;
1	Click 125cc, Blue, 2019	1	Лёгкий скутер, отлично подходит для поездок по острову.	\N	\N
2	Aerox 155cc, ABS, Green, 2018	2	Комфортный скутер для двух человек, ABS, экономичный.	\N	\N
3	PCX 150cc, Silver, 2018	2	Комфортный скутер для двух человек, ABS, экономичный.	\N	\N
4	PCX 150cc, Blue, 2019	2	Комфортный скутер для двух человек, ABS, экономичный.	\N	\N
5	PCX 150cc, Black, 2018	2	Комфортный скутер для двух человек, ABS, экономичный.	\N	\N
6	ADV 160cc, ABS, Black, 2022	2	ADV-модель с высоким клиренсом и мощным двигателем.	\N	\N
7	N-Max 155cc, ABS, Blue, 2022	2	Удобный городской скутер с ABS, подходит для всех дорог.	\N	\N
8	N-Max 155cc, ABS, Black, 2022	2	Удобный городской скутер с ABS, подходит для всех дорог.	\N	\N
9	PCX 160cc, ABS, Black, 2022	2	Новая версия PCX с улучшенным двигателем и дизайном.	\N	\N
10	PCX 160cc, White, 2022	2	Новая версия PCX с улучшенным двигателем и дизайном.	\N	\N
11	PCX 160cc, ABS, Black, 2023	2	Новая версия PCX с улучшенным двигателем и дизайном.	\N	\N
12	PCX 160cc, ABS, Blue, 2023	2	Новая версия PCX с улучшенным двигателем и дизайном.	\N	\N
13	ADV 160cc, ABS, Red, 2024	2	ADV-модель с высоким клиренсом и мощным двигателем.	\N	\N
14	Forza 350cc, ABS, Black, 2023	3	Максимальный комфорт и мощность. Идеален для длительных поездок.	\N	\N
15	Xmax 300cc, ABS, Black, 2024	3	Максимальный комфорт и мощность. Идеален для длительных поездок.	\N	\N
16	ADV 350cc, ABS, Black, 2025	3	ADV-серия с максимальной мощностью и проходимостью.	\N	\N
\.


--
-- TOC entry 4884 (class 0 OID 16729)
-- Dependencies: 224
-- Data for Name: categories; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.categories (id, name, description) FROM stdin;
1	Light Scooters	110cc–125cc
2	Comfort Scooters	150cc–160cc
3	Maxy Scooters	300cc–350cc
\.


--
-- TOC entry 4878 (class 0 OID 16390)
-- Dependencies: 218
-- Data for Name: knex_migrations; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.knex_migrations (id, name, batch, migration_time) FROM stdin;
30	001_create_users.js	1	2025-07-02 21:56:59.206+03
31	002_create_categories.js	1	2025-07-02 21:56:59.211+03
32	003_create_bikes.js	1	2025-07-02 21:56:59.223+03
33	004_create_seasons.js	1	2025-07-02 21:56:59.229+03
34	006_create_bike_prices.js	1	2025-07-02 21:56:59.238+03
35	007_create_rentals.js	1	2025-07-02 21:56:59.246+03
36	008_add_lang_to_users.js	1	2025-07-02 21:56:59.248+03
37	009_create_sessions.js	1	2025-07-02 21:56:59.253+03
38	010_add_desc_to_categories.js	1	2025-07-02 21:56:59.256+03
39	011_remove_bike_id_from_bikes.js	2	2025-12-24 01:08:49.113+03
40	012_add_accept_terms_to_rentals.js	2	2025-12-24 01:08:49.124+03
41	013_extend_rentals_for_wizard.js	3	2025-12-24 02:05:40.516+03
42	014_create_no_availability_requests.js	3	2025-12-24 02:05:40.55+03
43	015_add_contract_deposit_fields.js	4	2025-12-24 02:53:40.845+03
44	016_create_reminders.js	5	2025-12-24 03:02:26.9+03
45	017_add_updated_at_to_rentals.js	6	2025-12-24 03:04:58.362+03
46	018_add_emoji_to_bikes.js	7	2026-01-10 15:50:50.378+03
47	019_add_deposit_note_to_rentals.js	7	2026-01-10 15:50:50.383+03
\.


--
-- TOC entry 4880 (class 0 OID 16397)
-- Dependencies: 220
-- Data for Name: knex_migrations_lock; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.knex_migrations_lock (index, is_locked) FROM stdin;
2	0
\.


--
-- TOC entry 4895 (class 0 OID 96426)
-- Dependencies: 235
-- Data for Name: no_availability_requests; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.no_availability_requests (id, user_id, start_date, end_date, category_id, comment, created_at) FROM stdin;
\.


--
-- TOC entry 4897 (class 0 OID 96442)
-- Dependencies: 237
-- Data for Name: reminders; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.reminders (id, rental_id, type, send_at, sent, created_at) FROM stdin;
13	85	start_24h	2026-01-28 04:00:00+03	t	2026-01-08 20:33:02.496912+03
14	85	start_1h	2026-01-29 03:00:00+03	t	2026-01-08 20:33:02.496912+03
15	85	end_24h	2026-01-30 04:00:00+03	t	2026-01-08 20:33:02.496912+03
16	85	end_1h	2026-01-31 03:00:00+03	t	2026-01-08 20:33:02.496912+03
21	87	start_24h	2026-01-14 04:00:00+03	t	2026-01-10 15:52:20.659476+03
22	87	start_1h	2026-01-15 03:00:00+03	t	2026-01-10 15:52:20.659476+03
23	87	end_24h	2026-01-17 04:00:00+03	t	2026-01-10 15:52:20.659476+03
24	87	end_1h	2026-01-18 03:00:00+03	t	2026-01-10 15:52:20.659476+03
\.


--
-- TOC entry 4892 (class 0 OID 16781)
-- Dependencies: 232
-- Data for Name: rentals; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.rentals (id, user_id, bike_id, start_date, end_date, total_price, status, comment, created_at, confirmed_at, accept_terms, start_at, end_at, helmets_qty, delivery_required, delivery_address, docs_missing, booking_public_id, deposit, deposit_paid, deposit_required, contract_file_id, updated_at, deposit_note) FROM stdin;
84	23	14	2026-01-15	2026-01-18	3400	cancelled_by_client	\N	2026-01-08 20:23:16.685177+03	2026-01-08 20:23:34.66+03	t	2026-01-15 13:00:00+03	2026-01-18 16:00:00+03	1	t	уыавыва	f	DP-20260108-8UIW	\N	f	\N	\N	2026-01-08 20:32:49.659+03	\N
85	23	14	2026-01-29	2026-01-31	2550	approved	\N	2026-01-08 20:32:22.398461+03	2026-01-08 20:33:01.985+03	t	\N	\N	0	f	\N	f	DP-20260108-IRIR	\N	f	\N	\N	\N	\N
86	23	15	2026-01-08	2026-01-18	9427	cancelled	\N	2026-01-08 20:47:35.313451+03	2026-01-10 15:52:19.836+03	t	\N	\N	0	f	\N	f	DP-20260108-LBLS	\N	f	\N	\N	2026-01-10 15:52:26.035+03	\N
87	23	10	2026-01-15	2026-01-18	1400	approved	\N	2026-01-10 15:52:12.651633+03	2026-01-10 15:52:19.836+03	t	\N	\N	0	f	\N	f	DP-20260110-D7DH	\N	f	\N	\N	\N	123123
76	23	10	2025-12-02	2025-12-06	1750	cancelled_by_client	\N	2025-12-16 16:21:58.851884+03	2025-12-24 02:38:45.598+03	t	\N	\N	0	f	\N	f	\N	\N	f	\N	\N	2025-12-24 03:05:10.281+03	\N
78	23	14	2025-12-19	2025-12-21	2550	cancelled_by_client	\N	2025-12-24 01:13:47.455328+03	2025-12-24 02:38:45.598+03	t	\N	\N	0	f	\N	f	\N	\N	f	\N	\N	2025-12-24 23:51:18.058+03	\N
79	23	6	2025-12-27	2025-12-28	10	cancelled_by_client	\N	2025-12-24 02:31:43.863349+03	2025-12-24 02:38:45.598+03	t	2025-12-27 10:00:00+03	2025-12-28 17:00:00+03	1	t	123	f	\N	\N	f	\N	\N	2025-12-24 23:51:23.82+03	\N
81	23	1	2026-01-01	2026-01-08	1944	cancelled_by_client	\N	2025-12-24 03:29:45.837164+03	2025-12-24 03:29:50.235+03	t	2026-01-01 10:00:00+03	2026-01-08 10:00:00+03	0	f	\N	f	DP-20251224-2EUE	\N	f	\N	\N	2026-01-08 19:49:33.211+03	\N
75	23	12	2025-12-01	2025-12-05	2000	cancelled_by_client	\N	2025-12-16 15:36:11.997881+03	2025-12-16 15:36:14.615+03	f	\N	\N	0	f	\N	f	\N	\N	f	\N	\N	2026-01-08 20:20:46.569+03	\N
80	23	14	2026-01-01	2026-01-09	7074	cancelled_by_client	123	2025-12-24 02:45:30.473688+03	2025-12-24 02:45:35.395+03	t	2026-01-01 10:00:00+03	2026-01-09 10:00:00+03	1	t	123123	f	\N	\N	f	\N	\N	2026-01-08 20:20:50.937+03	\N
82	23	14	2026-01-08	2026-01-18	8646	cancelled_by_client	\N	2026-01-08 19:53:10.900509+03	\N	f	\N	\N	0	f	\N	f	DP-20260108-2FAP	\N	f	\N	\N	2026-01-08 20:22:37.536+03	\N
83	23	13	2026-01-15	2026-01-18	1800	cancelled	\N	2026-01-08 20:16:50.30779+03	2026-01-08 20:23:34.66+03	t	\N	\N	0	f	\N	f	DP-20260108-54R8	\N	f	\N	\N	2026-01-08 20:23:59.764+03	\N
\.


--
-- TOC entry 4888 (class 0 OID 16753)
-- Dependencies: 228
-- Data for Name: seasons; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.seasons (id, name, months) FROM stdin;
1	Low	{6,7,8,9}
2	Middle	{4,5,10,11}
3	High	{12,1,2,3}
\.


--
-- TOC entry 4893 (class 0 OID 16802)
-- Dependencies: 233
-- Data for Name: sessions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.sessions (user_id, data) FROM stdin;
640738274	{}
234666091	{}
336811895	{"step": null, "booking": {"step": "dates_selected", "endDate": "2025-07-13", "scenario": "date_first", "startDate": "2025-07-07", "totalPrice": 1799, "calendarYear": 2025, "calendarMonth": 7, "selectedBikeId": 9}, "scenario": null}
155786530	{}
1948997922	{"lang": "ru", "step": null, "booking": {"step": "dates_selected", "notes": null, "endDate": "2026-02-14", "endTime": null, "helmets": 0, "scenario": "date_first", "startDate": "2026-02-10", "startTime": null, "categoryId": 3, "timeSource": null, "totalPrice": null, "pricePerDay": null, "calendarYear": 2026, "priceUnknown": false, "calendarMonth": 2, "selectedBikeId": null, "deliveryAddress": null, "deliveryRequired": false}, "scenario": null, "acceptTerms": true, "optionsScope": "process", "commentReturn": null, "returnToProfile": null, "adminDeclineRentalId": null, "adminDepositRentalId": 87}
1087968824	{}
5044659103	{"lang": "ru", "step": null, "booking": {"step": null, "notes": null, "endDate": null, "endTime": null, "helmets": 0, "scenario": null, "startDate": null, "startTime": null, "timeSource": null, "totalPrice": null, "pricePerDay": null, "calendarYear": 2026, "priceUnknown": false, "calendarMonth": 1, "selectedBikeId": null, "deliveryAddress": null, "deliveryRequired": false}, "scenario": null, "commentReturn": null, "returnToProfile": null}
385700028	{"lang": "ru", "step": null, "booking": {"step": null, "notes": null, "endDate": null, "endTime": null, "helmets": 0, "scenario": null, "startDate": null, "startTime": null, "timeSource": null, "totalPrice": null, "pricePerDay": null, "calendarYear": 2026, "priceUnknown": false, "calendarMonth": 1, "selectedBikeId": null, "deliveryAddress": null, "deliveryRequired": false}, "scenario": null, "commentReturn": null, "returnToProfile": null}
371476497	{}
334083819	{}
481839477	{}
\.


--
-- TOC entry 4882 (class 0 OID 16715)
-- Dependencies: 222
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.users (id, telegram_id, telegram_name, name, phone, passport_photo_file_id, is_admin, meta, created_at, lang) FROM stdin;
37	385700028	drivephangan	Andrew	+66971819946	\N	f	{"passport_number": "GA436839"}	2025-07-21 15:18:58.809254+03	ru
54	5044659103	\N	Andrew Pol	+66971819946	\N	f	{"passport_number": "567890"}	2026-01-10 00:03:01.963135+03	ru
27	336811895	oleksiipol	Oleksii	+380637660508	336811895_passport.jpg	f	{}	2025-07-17 14:29:15.484055+03	ru
23	1948997922	danykrasniy	Даниил	79515328255	1948997922_passport.jpg	t	{"passport_number": "321 12312"}	2025-07-12 19:02:43.412651+03	ru
\.


--
-- TOC entry 4913 (class 0 OID 0)
-- Dependencies: 229
-- Name: bike_prices_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.bike_prices_id_seq', 225, true);


--
-- TOC entry 4914 (class 0 OID 0)
-- Dependencies: 225
-- Name: bikes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.bikes_id_seq', 16, true);


--
-- TOC entry 4915 (class 0 OID 0)
-- Dependencies: 223
-- Name: categories_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.categories_id_seq', 3, true);


--
-- TOC entry 4916 (class 0 OID 0)
-- Dependencies: 217
-- Name: knex_migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.knex_migrations_id_seq', 47, true);


--
-- TOC entry 4917 (class 0 OID 0)
-- Dependencies: 219
-- Name: knex_migrations_lock_index_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.knex_migrations_lock_index_seq', 2, true);


--
-- TOC entry 4918 (class 0 OID 0)
-- Dependencies: 234
-- Name: no_availability_requests_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.no_availability_requests_id_seq', 1, false);


--
-- TOC entry 4919 (class 0 OID 0)
-- Dependencies: 236
-- Name: reminders_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.reminders_id_seq', 24, true);


--
-- TOC entry 4920 (class 0 OID 0)
-- Dependencies: 231
-- Name: rentals_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.rentals_id_seq', 87, true);


--
-- TOC entry 4921 (class 0 OID 0)
-- Dependencies: 227
-- Name: seasons_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.seasons_id_seq', 3, true);


--
-- TOC entry 4922 (class 0 OID 0)
-- Dependencies: 221
-- Name: users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.users_id_seq', 57, true);


--
-- TOC entry 4716 (class 2606 OID 16769)
-- Name: bike_prices bike_prices_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.bike_prices
    ADD CONSTRAINT bike_prices_pkey PRIMARY KEY (id);


--
-- TOC entry 4710 (class 2606 OID 16744)
-- Name: bikes bikes_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.bikes
    ADD CONSTRAINT bikes_pkey PRIMARY KEY (id);


--
-- TOC entry 4708 (class 2606 OID 16734)
-- Name: categories categories_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.categories
    ADD CONSTRAINT categories_pkey PRIMARY KEY (id);


--
-- TOC entry 4702 (class 2606 OID 16402)
-- Name: knex_migrations_lock knex_migrations_lock_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.knex_migrations_lock
    ADD CONSTRAINT knex_migrations_lock_pkey PRIMARY KEY (index);


--
-- TOC entry 4700 (class 2606 OID 16395)
-- Name: knex_migrations knex_migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.knex_migrations
    ADD CONSTRAINT knex_migrations_pkey PRIMARY KEY (id);


--
-- TOC entry 4722 (class 2606 OID 96434)
-- Name: no_availability_requests no_availability_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.no_availability_requests
    ADD CONSTRAINT no_availability_requests_pkey PRIMARY KEY (id);


--
-- TOC entry 4724 (class 2606 OID 96449)
-- Name: reminders reminders_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.reminders
    ADD CONSTRAINT reminders_pkey PRIMARY KEY (id);


--
-- TOC entry 4718 (class 2606 OID 16790)
-- Name: rentals rentals_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.rentals
    ADD CONSTRAINT rentals_pkey PRIMARY KEY (id);


--
-- TOC entry 4712 (class 2606 OID 16762)
-- Name: seasons seasons_name_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.seasons
    ADD CONSTRAINT seasons_name_unique UNIQUE (name);


--
-- TOC entry 4714 (class 2606 OID 16760)
-- Name: seasons seasons_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.seasons
    ADD CONSTRAINT seasons_pkey PRIMARY KEY (id);


--
-- TOC entry 4720 (class 2606 OID 16808)
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (user_id);


--
-- TOC entry 4704 (class 2606 OID 16725)
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- TOC entry 4706 (class 2606 OID 16727)
-- Name: users users_telegram_id_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_telegram_id_unique UNIQUE (telegram_id);


--
-- TOC entry 4726 (class 2606 OID 16770)
-- Name: bike_prices bike_prices_bike_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.bike_prices
    ADD CONSTRAINT bike_prices_bike_id_foreign FOREIGN KEY (bike_id) REFERENCES public.bikes(id);


--
-- TOC entry 4727 (class 2606 OID 16775)
-- Name: bike_prices bike_prices_season_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.bike_prices
    ADD CONSTRAINT bike_prices_season_id_foreign FOREIGN KEY (season_id) REFERENCES public.seasons(id);


--
-- TOC entry 4725 (class 2606 OID 16747)
-- Name: bikes bikes_category_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.bikes
    ADD CONSTRAINT bikes_category_id_foreign FOREIGN KEY (category_id) REFERENCES public.categories(id);


--
-- TOC entry 4730 (class 2606 OID 96435)
-- Name: no_availability_requests no_availability_requests_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.no_availability_requests
    ADD CONSTRAINT no_availability_requests_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- TOC entry 4731 (class 2606 OID 96450)
-- Name: reminders reminders_rental_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.reminders
    ADD CONSTRAINT reminders_rental_id_foreign FOREIGN KEY (rental_id) REFERENCES public.rentals(id) ON DELETE CASCADE;


--
-- TOC entry 4728 (class 2606 OID 16796)
-- Name: rentals rentals_bike_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.rentals
    ADD CONSTRAINT rentals_bike_id_foreign FOREIGN KEY (bike_id) REFERENCES public.bikes(id);


--
-- TOC entry 4729 (class 2606 OID 16791)
-- Name: rentals rentals_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.rentals
    ADD CONSTRAINT rentals_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id);


-- Completed on 2026-02-05 00:40:53

--
-- PostgreSQL database dump complete
--

