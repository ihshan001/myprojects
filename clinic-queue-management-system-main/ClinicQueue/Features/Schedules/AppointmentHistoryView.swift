import SwiftUI

struct AppointmentHistoryView: View {
    
    @StateObject var session = SessionManagerV2()
    @State private var navigateToQueue = false
    
    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 30) {
                    
                    Text("Appointment History")
                        .font(.system(size: 20, weight: .bold))
                        .frame(maxWidth: .infinity, alignment: .center)
                        .padding(.horizontal)
                    
                    let todayAppointments = session.upcomingAppointments.filter {
                        Calendar.current.isDateInToday($0.date)
                    }
                    
                    let futureAppointments = session.upcomingAppointments.filter {
                        $0.date > Date()
                    }
                    
                    let completedAppointments = session.upcomingAppointments.filter {
                        $0.date < Date()
                    }
                    
                    if !todayAppointments.isEmpty {
                        VStack(alignment: .leading, spacing: 12) {
                            
                            Text("Today")
                                .font(.system(size: 20, weight: .bold))
                                .padding(.horizontal)
                            
                            ForEach(todayAppointments) { appointment in
                                InfoCard(
                                    data: createCardData(from: appointment, isToday: true)
                                )
                                .padding(.horizontal)
                                .onTapGesture {
                                    goToQueuePage(appointment)
                                }
                            }
                        }
                    } else {
                        Text("No appointments today")
                            .foregroundColor(.gray)
                            .padding()
                    }
                    
                    
                    if !futureAppointments.isEmpty {
                        VStack(alignment: .leading, spacing: 12) {
                            
                            Text("Upcoming")
                                .font(.system(size: 20, weight: .bold))
                                .padding(.horizontal)
                            
                            ForEach(futureAppointments) { appointment in
                                InfoCard(
                                    data: createCardData(from: appointment, isToday: false)
                                )
                                .padding(.horizontal)
                                .opacity(0.5)
                            }
                        }
                    }
                    
                    if !completedAppointments.isEmpty {
                        VStack(alignment: .leading, spacing: 12) {
                            
                            Text("Completed")
                                .font(.system(size: 20, weight: .bold))
                                .padding(.horizontal)
                            
                            ForEach(completedAppointments) { appointment in
                                InfoCard(
                                    data: createCardData(from: appointment, isToday: false)
                                )
                                .padding(.horizontal)
                                .opacity(0.5)
                            }
                        }
                    }
                }
                .padding(.vertical)
            }
            .onAppear {
                session.upcomingAppointments = DummyAppointments.generate()
            }
            .navigationDestination(isPresented: $navigateToQueue) {
                Queue()
                    .environmentObject(session)
            }
        }
    }
    
    

    private func goToQueuePage(_ appointment: UpcomingAppointment) {

        session.activities = appointment.activities
        
        if !session.activities.isEmpty {
            session.activities[0].isSelected = true
            session.activities[0].queueStage = .wait
        }
        session.currentService = .appointment
        navigateToQueue = true
    }
    
    
    private func createCardData(from appointment: UpcomingAppointment, isToday: Bool) -> InfoCardData {
        
        let doctor = appointment.activities.first?.selectedDoctor
        
        return InfoCardData(
            image: doctor?.image ?? Image(systemName: "person.crop.circle.fill"),
            heading: doctor?.heading ?? "Doctor",
            subheading: doctor?.subheading ?? "-",
            activeQueueCount: doctor?.activeQueueCount,
            detail1: ("Queue", doctor?.activeQueueCount ?? "-"),
            detail2: ("Location", doctor?.detail2?.value ?? "-"),
            price: "$\(String(format: "%.2f", appointment.totalFee))",
            availableDates: doctor?.availableDates,
            maxPatientsPerDay: doctor?.maxPatientsPerDay,
            isPriceButtonVisble: true,
            status: isToday || appointment.date > Date() ? .upcoming : .completed
        )
    }
}

#Preview {
    AppointmentHistoryView()
}
