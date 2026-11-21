package com.alsaeeddev.payrelay.data.local.db

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.test.runTest
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class OutboxDaoRobolectricTest {

    private lateinit var db: PayRelayDatabase
    private lateinit var dao: OutboxDao

    @Before
    fun setUp() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, PayRelayDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        dao = db.outboxDao()
    }

    @After
    fun tearDown() {
        db.close()
    }

    @Test
    fun getPendingInOrder_returnsFifoAndIgnoresDuplicateKey() = runTest {
        val id1 = dao.insertIfNew(
            OutboxEntity(
                idempotencyKey = "key_b",
                sender = "Easypaisa",
                body = "Msg 2",
                receivedAtMs = 2000L,
                simSlot = 1,
                createdAtMs = 2000L
            )
        )
        val id2 = dao.insertIfNew(
            OutboxEntity(
                idempotencyKey = "key_a",
                sender = "JazzCash",
                body = "Msg 1",
                receivedAtMs = 1000L,
                simSlot = 0,
                createdAtMs = 1000L
            )
        )
        val duplicateId = dao.insertIfNew(
            OutboxEntity(
                idempotencyKey = "key_a",
                sender = "JazzCash",
                body = "Msg 1",
                receivedAtMs = 1000L,
                simSlot = 0,
                createdAtMs = 1000L
            )
        )

        assertNotEquals(-1L, id1)
        assertNotEquals(-1L, id2)
        assertEquals(-1L, duplicateId)

        val pending = dao.getPendingInOrder()
        assertEquals(2, pending.size)
        assertEquals("key_a", pending[0].idempotencyKey)
        assertEquals("key_b", pending[1].idempotencyKey)
    }

    @Test
    fun markSent_wipesSmsBodyAndUpdatesStatus() = runTest {
        val id = dao.insertIfNew(
            OutboxEntity(
                idempotencyKey = "key_sent",
                sender = "JazzCash",
                body = "TID 999 Rs 1500",
                receivedAtMs = 1000L,
                simSlot = 0,
                createdAtMs = 1000L
            )
        )

        dao.markSent(id = id, sentAtMs = 1500L, httpCode = 200)

        assertEquals(0, dao.countPending())
        val recent = dao.observeRecent(10).first()
        assertEquals(1, recent.size)
        assertEquals(OutboxStatus.SENT, recent[0].status)
        assertEquals("", recent[0].body)
        assertEquals(200, recent[0].lastHttpCode)
    }

    @Test
    fun pruneCompletedBefore_deletesOldCompletedButKeepsPending() = runTest {
        val oldSentId = dao.insertIfNew(
            OutboxEntity(
                idempotencyKey = "old_sent",
                sender = "JazzCash",
                body = "Old",
                receivedAtMs = 1000L,
                simSlot = 0,
                createdAtMs = 1000L
            )
        )
        dao.markSent(oldSentId, sentAtMs = 1100L, httpCode = 200)

        dao.insertIfNew(
            OutboxEntity(
                idempotencyKey = "old_pending",
                sender = "Easypaisa",
                body = "Still pending",
                receivedAtMs = 1000L,
                simSlot = 0,
                createdAtMs = 1000L
            )
        )

        val deleted = dao.pruneCompletedBefore(cutoffMs = 5000L)
        assertEquals(1, deleted)
        assertEquals(1, dao.countPending())
    }
}
