// Independent synthetic producer and native output oracle. SOURCE ONLY.
// production_procedures.dm is extracted verbatim from the pinned Civ13 commit.
#define EXCEPTION(message) new /exception(message)
#define TICK_CHECK fixture_work()
#define islist(A) istype(A, /list)

world
	name = "Civilizationbot independent save fixture"
	maxx = 1
	maxy = 1
	maxz = 1
	visibility = 0
	sleep_offline = 0

/world/OpenPort(port)
	return ..("none")

/turf/save_bridge_fixture
/obj/save_bridge_fixture
	var/native_id = 0
/datum/save_bridge_fixture
/obj/map_metadata
/datum/save_export_frame
	var/list/content_refs = list()

var/fixture_work_count = 0
var/obj/save_bridge_fixture/fixture_left
var/obj/save_bridge_fixture/fixture_right

/proc/fixture_check(ok)
	if(!ok) throw new /exception("native_fixture_assertion_failed")

/proc/fixture_work()
	fixture_work_count++
	fixture_check(fixture_work_count <= 65536)

/map_storage
	var/zone_export_state
	var/list/saving_lists = list()
	var/list/saving_list_pins = list()
	var/datum/save_export_frame/active_frame
	proc/save_work_check()
		fixture_work()
	proc/require_export_frame(datum/save_export_frame/frame)
		fixture_check(frame == active_frame)
	proc/encode_zone_reference(value)
		throw new /exception("native_fixture_zone_unsupported")
	// Deliberately not a copy of production entity discovery. Only two known
	// synthetic references are admitted; fields and IDs are independently authored.
	proc/BuildVarDirectory(savefile/F, datum/A, contents)
		fixture_check(A == fixture_left || A == fixture_right)
		return A == fixture_left ? 512 : 513

#include "production_procedures.dm"

/proc/fixture_write(name, version, control)
	fixture_check(!fexists(name))
	var/savefile/F = new(name)
	F["/format_version"] << version
	F["/generation"] << "round-v1:synthetic-fixture-v1"
	F["/maxx"] << 1
	F["/maxy"] << 1
	F["/maxz"] << 1
	F["/map/1/1/1"] << 1
	var/map_storage/S = new
	for(var/id = 1, id <= 513, id++)
		fixture_work()
		var/path = S.entry_path(F, id)
		F["[path]/type"] << (id == 1 ? /turf/save_bridge_fixture : /obj/save_bridge_fixture)
	var/base = S.entry_path(F, 1)
	F["[base]/label"] << S.encode_saved_value(F, "**null")
	F["[base]/score"] << S.encode_saved_value(F, 5)
	F["[base]/peer"] << S.encode_saved_value(F, fixture_right)
	var/list/L = list()
	L["self"] = L
	L["peer"] = fixture_right
	L["empty"] = list()
	L["nil"] = null
	L["typed"] = /datum/save_bridge_fixture
	L["literal_path"] = "/datum/save_bridge_fixture"
	L.len++
	L[L.len] = 7
	L.len++
	L[L.len] = 7
	F["[base]/shared"] << S.encode_saved_value(F, L)
	F["[base]/also_shared"] << S.encode_saved_value(F, L)
	F["/entries/0/512/peer"] << S.encode_saved_value(F, fixture_right)
	F["/entries/1/513/peer"] << S.encode_saved_value(F, fixture_left)
	var/datum/save_export_frame/frame = new
	S.active_frame = frame
	if(version == 2)
		frame.content_refs += 513
	else
		for(var/id = 2, id <= 513, id++) frame.content_refs += id
	F["[base]/content"] << S.encode_saved_contents(F, frame)
	if(control == "wrong-version") F["/format_version"] << 4
	if(control == "unknown-field") F["[base]/unknown"] << "synthetic-refusal"
	if(control == "extra-buffer")
		F.cd = "[base]/score"
		F.eof = 1
		F << 99
	if(control == "resource") F["[base]/score"] << list("kind" = "value", "value" = 'fixture-resource.txt')
	if(control == "instance")
		var/datum/save_bridge_fixture/D = new
		F["[base]/score"] << list("kind" = "value", "value" = D)
	if(control == "missing-type")
		F.cd = base
		F.dir.Remove("type")
	if(control == "bad-ref") F["[base]/peer"] << list("kind" = "entry", "ref" = 9999)
	if(control == "unknown-kind") F["[base]/peer"] << list("kind" = "zone", "id" = "unsupported")
	F.Flush()
	F = null

// This oracle does not invoke the bridge, its writer, a PHP fixture builder,
// or production encoding. It checks closed output through independent reads.
/proc/oracle_names(savefile/F, path, list/expected)
	F.cd = path
	F.eof = 0
	fixture_check(F.eof && F.dir.len == expected.len)
	for(var/name in expected) fixture_check(F.dir.Find(name))

/proc/oracle_read(savefile/F, path)
	F.cd = path
	F.eof = 0
	fixture_check(!F.dir.len && !F.eof)
	var/value
	F >> value
	fixture_check(F.eof)
	return value

/proc/oracle_scalar(actual, expected)
	if(isnull(expected))
		fixture_check(isnull(actual))
	else if(isnum(expected))
		fixture_check(isnum(actual) && actual == expected)
	else if(ispath(expected))
		fixture_check(ispath(actual) && actual == expected)
	else
		fixture_check(istext(actual) && actual == expected)

/proc/oracle_envelope(savefile/F, path, kind, expected)
	var/list/V = oracle_read(F, path)
	var/payload = kind == "value" ? "value" : "ref"
	fixture_check(islist(V) && V.len == 2 && V.Find("kind") && V.Find(payload) && V["kind"] == kind)
	oracle_scalar(V[payload], expected)

/proc/oracle_verify(version)
	fixture_check(version == 2 || version == 3)
	var/savefile/F = new("candidate.sav")
	oracle_names(F, "/", list("format_version", "generation", "maxx", "maxy", "maxz", "map", "entries", "lists"))
	oracle_scalar(oracle_read(F, "/format_version"), version)
	oracle_scalar(oracle_read(F, "/generation"), "round-v1:synthetic-fixture-v1")
	for(var/axis in list("maxx", "maxy", "maxz")) oracle_scalar(oracle_read(F, "/[axis]"), 1)
	for(var/path in list("/map", "/map/1", "/map/1/1")) oracle_names(F, path, list("1"))
	oracle_scalar(oracle_read(F, "/map/1/1/1"), 1)
	oracle_names(F, "/entries", list("0", "1"))
	var/list/first_shard = list()
	for(var/id = 1, id <= 512, id++) first_shard += "[id]"
	oracle_names(F, "/entries/0", first_shard)
	oracle_names(F, "/entries/1", list("513"))
	for(var/id = 1, id <= 513, id++)
		fixture_work()
		var/path = id <= 512 ? "/entries/0/[id]" : "/entries/1/513"
		var/list/names = id == 1 ? list("type", "label", "score", "peer", "shared", "also_shared", "content") : (id >= 512 ? list("type", "peer") : list("type"))
		oracle_names(F, path, names)
		oracle_scalar(oracle_read(F, "[path]/type"), id == 1 ? /turf/save_bridge_fixture : /obj/save_bridge_fixture)
	oracle_envelope(F, "/entries/0/1/label", "value", "**null")
	oracle_envelope(F, "/entries/0/1/score", "value", 6)
	oracle_envelope(F, "/entries/0/1/peer", "entry", 513)
	oracle_envelope(F, "/entries/0/1/shared", "list", 1)
	oracle_envelope(F, "/entries/0/1/also_shared", "list", 1)
	oracle_envelope(F, "/entries/0/512/peer", "entry", 513)
	oracle_envelope(F, "/entries/1/513/peer", "entry", 512)
	var/content = oracle_read(F, "/entries/0/1/content")
	if(version == 2)
		oracle_scalar(content, "513")
	else
		fixture_check(islist(content) && length(content) == 2)
		var/list/first = list()
		var/list/last = list()
		for(var/id = 2, id <= 501, id++) first += "[id]"
		for(var/id = 502, id <= 513, id++) last += "[id]"
		oracle_scalar(content[1], jointext(first, "&"))
		oracle_scalar(content[2], jointext(last, "&"))
	oracle_names(F, "/lists", list("1", "2"))
	var/list/list_fields = list("length")
	for(var/i = 1, i <= 8, i++) list_fields += "[i]"
	oracle_names(F, "/lists/1", list_fields)
	oracle_scalar(oracle_read(F, "/lists/1/length"), 8)
	oracle_names(F, "/lists/2", list("length"))
	oracle_scalar(oracle_read(F, "/lists/2/length"), 0)
	var/list/keys = list("self", "peer", "empty", "nil", "typed", "literal_path", 7, 7)
	var/list/kinds = list("list", "entry", "list", "value", "value", "value", "value", "value")
	var/list/values = list(1, 513, 2, null, /datum/save_bridge_fixture, "/datum/save_bridge_fixture", null, null)
	for(var/i = 1, i <= 8, i++)
		oracle_names(F, "/lists/1/[i]", list("key", "value"))
		oracle_envelope(F, "/lists/1/[i]/key", "value", keys[i])
		oracle_envelope(F, "/lists/1/[i]/value", kinds[i], values[i])
	F = null

/world/New()
	..()
	var/status = "rejected"
	try
		fixture_check(world.byond_version == 516 && world.byond_build == 1688)
		var/list/request = json_decode(file2text("fixture-request.json"))
		fixture_check(request["schema"] == "civilizationbot.fixture-request.v1")
		if(request["operation"] == "generate")
			fixture_check(request.len == 2)
			fixture_left = new
			fixture_right = new
			fixture_write("fixture-v2.sav", 2, null)
			fixture_write("fixture-v3.sav", 3, null)
			for(var/control in list("wrong-version", "unknown-field", "extra-buffer", "resource", "instance", "missing-type", "bad-ref", "unknown-kind"))
				fixture_write("fixture-[control].sav", 3, control)
		else
			fixture_check(request["operation"] == "verify" && request.len == 3 && fexists("candidate.sav"))
			var/original = md5(file("candidate.sav"))
			oracle_verify(request["version"])
			fixture_check(original == md5(file("candidate.sav")))
		status = "ok"
	catch
		status = "rejected"
	text2file(json_encode(list("schema" = "civilizationbot.fixture-receipt.v1", "status" = status, "runtime" = "[world.byond_version].[world.byond_build]")), "fixture-receipt.json")
	shutdown()
