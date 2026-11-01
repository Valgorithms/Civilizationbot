// SOURCE ONLY: not compiled or executed by offline tests.
// Native savefile reader/writer; no binary format inference, game includes,
// saved-entity construction, shell calls, network or operator-selected paths.
#define SB_PROTOCOL "civilizationbot.native-save-bridge.v1"
#define SB_PROFILE "civ13-synthetic-level.v1"
#define SB_SOURCE "75e381e0d72211e923bffd8893bdd786c0f6d392"

world
	name = "Civilizationbot synthetic save bridge"
	maxx = 1
	maxy = 1
	maxz = 1
	visibility = 0
	sleep_offline = 0

/world/OpenPort(port)
	return ..("none")

// Only inert symbolic type paths exist in this image. Never deserialize an
// actual datum/atom instance: the Civ13 saver persists type paths and envelopes.
/datum/Read(savefile/F)
	throw new /exception("save_native_object_unsupported")
/atom/Read(savefile/F)
	throw new /exception("save_native_object_unsupported")
/datum/save_bridge_fixture
/turf/save_bridge_fixture
/obj/save_bridge_fixture

var/list/sb_ids
var/list/sb_nodes
var/list/sb_pins
var/list/sb_roots
var/list/sb_types = list(/datum/save_bridge_fixture, /turf/save_bridge_fixture, /obj/save_bridge_fixture)
var/sb_work

/proc/sb_check(ok)
	if(!ok) throw new /exception("save_native_profile_invalid")

/proc/sb_tick()
	sb_work++
	sb_check(sb_work <= 65536)

/proc/sb_leaf(path)
	if(path in list("/format_version", "/generation", "/maxx", "/maxy", "/maxz")) return 1
	var/regex/R = regex(@(##)^/map/[1-9][0-9]*/[1-9][0-9]*/[1-9][0-9]*$##)
	if(R.Find(path)) return 1
	R = regex(@(##)^/entries/(0|[1-9][0-9]*)/[1-9][0-9]*/(type|content|label|score|peer|shared|also_shared)$##)
	if(R.Find(path)) return 1
	R = regex(@(##)^/lists/[1-9][0-9]*/(length|[1-9][0-9]*/(key|value))$##)
	return R.Find(path)

/proc/sb_branch(path)
	if(path in list("/", "/map", "/entries", "/lists")) return 1
	var/regex/R = regex(@(##)^/map/[1-9][0-9]*(/[1-9][0-9]*)?$##)
	if(R.Find(path)) return 1
	R = regex(@(##)^/entries/(0|[1-9][0-9]*)(/[1-9][0-9]*)?$##)
	if(R.Find(path)) return 1
	R = regex(@(##)^/lists/[1-9][0-9]*(/[1-9][0-9]*)?$##)
	return R.Find(path)

/proc/sb_value(value, depth = 0)
	sb_tick()
	sb_check(depth <= 32)
	if(isnull(value)) return list("tag" = "null")
	if(isnum(value))
		sb_check(!isnan(value) && !isinf(value) && value == round(value) && abs(value) <= 16777216)
		return list("tag" = "number", "value" = value)
	if(istext(value))
		sb_check(length(value) <= 65536)
		return list("tag" = "text", "value" = value)
	if(ispath(value))
		sb_check(value in sb_types)
		return list("tag" = "type", "value" = "[value]")
	// Native islist also accepts /alist; this profile explicitly rejects it.
	if(istype(value, /list))
		var/list/L = value
		var/id = sb_ids["\ref[L]"]
		if(id) return list("tag" = "list", "id" = id)
		sb_check(sb_nodes.len < 4096 && L.len <= 65536)
		id = "native:list[sb_nodes.len + 1]"
		sb_ids["\ref[L]"] = id
		sb_pins[id] = L // Prevent native ref recycling between independent reads.
		var/list/items = list()
		var/list/record = list("id" = id, "kind" = "list", "items" = items)
		sb_nodes += list(record)
		for(var/i = 1, i <= L.len, i++)
			var/key = L[i]
			var/associated = (!isnum(key) && !isnull(key)) ? L[key] : null
			items += list(list("key" = sb_value(key, depth + 1), "associated" = !isnull(associated), "value" = sb_value(associated, depth + 1)))
		return list("tag" = "list", "id" = id)
	throw new /exception("save_native_value_unsupported")

/proc/sb_walk(savefile/F, path, depth = 0)
	sb_tick()
	sb_check(depth <= 6)
	F.cd = path
	F.eof = 0
	var/list/names = F.dir.Copy()
	if(sb_leaf(path))
		sb_check(!names.len && !F.eof && sb_roots.len < 4096)
		var/value
		F >> value
		sb_check(F.eof) // Multiple values in one buffer must not be truncated.
		sb_roots += list(list("name" = path, "value" = sb_value(value)))
		return
	sb_check(sb_branch(path) && names.len && F.eof)
	for(var/name in names)
		sb_check(istext(name) && length(name) <= 64 && !findtext(name, "/"))
		var/child = path == "/" ? "/[name]" : "[path]/[name]"
		// Membership was inventoried before any read or directory change. Unknown
		// branches/fields reject without reading their values.
		sb_check(sb_leaf(child) || sb_branch(child))
		sb_walk(F, child, depth + 1)

/proc/sb_read(path)
	sb_ids = list()
	sb_nodes = list()
	sb_pins = list()
	sb_roots = list()
	sb_work = 0
	sb_check(fexists(path))
	var/savefile/F = new(path)
	sb_walk(F, "/")
	F = null
	return list("schema" = "civilizationbot.save-graph.v1", "roots" = sb_roots, "objects" = list(), "lists" = sb_nodes)

/proc/sb_decode_value(list/V, list/lists)
	sb_tick()
	sb_check(islist(V))
	switch(V["tag"])
		if("null") return null
		if("number")
			var/n = V["value"]
			sb_check(isnum(n) && !isnan(n) && !isinf(n) && n == round(n) && abs(n) <= 16777216)
			return n
		if("text")
			sb_check(istext(V["value"]) && length(V["value"]) <= 65536)
			return V["value"]
		if("type")
			var/type = text2path(V["value"])
			sb_check(type in sb_types)
			return type
		if("list")
			sb_check(lists.Find(V["id"]))
			return lists[V["id"]]
	throw new /exception("save_native_value_unsupported")

/proc/sb_write(list/G)
	sb_check(!fexists("output.sav") && G["schema"] == "civilizationbot.save-graph.v1")
	var/list/objects = G["objects"]
	sb_check(islist(objects) && !objects.len)
	var/list/lists = list()
	var/list/records = G["lists"]
	var/list/roots = G["roots"]
	sb_check(islist(records) && records.len <= 4096 && islist(roots) && roots.len <= 4096)
	for(var/record_index = 1, record_index <= records.len, record_index++)
		sb_check(istype(records[record_index], /list))
	for(var/root_index = 1, root_index <= roots.len, root_index++)
		sb_check(istype(roots[root_index], /list))
	for(var/list/R in records)
		sb_check(R["kind"] == "list" && istext(R["id"]) && !lists.Find(R["id"]))
		lists[R["id"]] = list()
	for(var/list/R in records)
		var/list/L = lists[R["id"]]
		var/list/items = R["items"]
		sb_check(islist(items) && items.len <= 65536)
		for(var/item_index = 1, item_index <= items.len, item_index++)
			sb_check(istype(items[item_index], /list))
		for(var/list/item in items)
			var/key = sb_decode_value(item["key"], lists)
			var/value = sb_decode_value(item["value"], lists)
			L.len++
			L[L.len] = key
			if(item["associated"])
				sb_check(!isnum(key) && !isnull(key) && !isnull(value))
				L[key] = value
	var/savefile/F = new("output.sav")
	var/list/seen = list()
	for(var/list/root in roots)
		var/path = root["name"]
		sb_check(istext(path) && sb_leaf(path) && !seen[path])
		seen[path] = 1
		F[path] << sb_decode_value(root["value"], lists)
	F.Flush()
	F = null
	// Reopen only the newly written disposable output; PHP compares semantics.
	return sb_read("output.sav")

/world/New()
	..()
	var/operation = "unknown"
	var/status = "rejected"
	var/list/graph = list()
	try
		sb_check(world.byond_version == 516 && world.byond_build == 1688)
		var/request_text = file2text("request.json")
		sb_check(istext(request_text) && length(request_text) <= 1048576)
		var/list/request = json_decode(request_text)
		sb_check(islist(request) && request["protocol"] == SB_PROTOCOL && request["profile"] == SB_PROFILE && request["source"] == SB_SOURCE)
		sb_check(istext(request["operation"]))
		sb_check(request["operation"] == "decode" || request["operation"] == "encode")
		operation = request["operation"]
		if(operation == "decode")
			sb_check(request.len == 4)
			var/original = md5(file("input.sav"))
			graph = sb_read("input.sav")
			sb_check(md5(file("input.sav")) == original)
		else if(operation == "encode")
			sb_check(request.len == 5)
			sb_work = 0
			graph = sb_write(request["graph"])
		else
			sb_check(0)
		status = "ok"
	catch
		// Fixed errors only. No saved values or native exception details leave.
		status = "rejected"
		graph = list()
	var/list/receipt = list("protocol" = SB_PROTOCOL, "profile" = SB_PROFILE, "source" = SB_SOURCE, "operation" = operation, "runtime" = "[world.byond_version].[world.byond_build]", "status" = status, "graph" = graph)
	var/response = json_encode(receipt)
	if(length(response) > 1048576)
		receipt["status"] = "rejected"
		receipt["graph"] = list()
		response = json_encode(receipt)
	if(length(response) <= 1048576) text2file(response, "response.json")
	shutdown()
