
import SwiftUI

let queues = ["11", "12", "13", "14"]
let times = ["~30–35 min", "~20–25 min", "~40–45 min", "~25–30 min"]


struct AppointmentView: View{
   
    var doctor: DoctorCardData
    @State private var activeIndex = 0
    
    let columns = [
        GridItem(.adaptive(minimum: 100), spacing: 4) 
    ]

    
    var body: some View {
        
        
        HeaderSection(title: "Appointment")
        ScrollView {
            VStack(spacing: 12){
                DisplayCard(props: doctor) {
                }
                
            }
            
            VStack(spacing:12){
                Text("Choose your queue").font(.app(.heading)).frame(maxWidth: .infinity, alignment: .leading)
                Text("We’ll recommend the fastest option. You can change if needed.")
                    .font(.app(.caption))
                    .foregroundColor(.gray)
                    .frame(maxWidth: .infinity, alignment: .leading)
            } .padding(.top, 20)
            
            
            LazyVGrid(columns: columns, spacing: 4) {
                ForEach(queues.indices, id: \.self) { i in
                    QueueButton(
                        title: queues[i],
                        subText: times[i],
                        action: { print("Selected queue: \(queues[i])") },
                        isActive: Binding(
                            get: { activeIndex == i },
                            set: { if $0 { activeIndex = i } }
                        )
                    )
                }
            }
            
            
            VStack(spacing:12){
                Text("Payment Details").font(.app(.heading)).frame(maxWidth: .infinity, alignment: .leading).padding(.bottom, 8)
                
                HStack {
                    Text("Consultation")
                        .font(.app(size: .lg, weight: .medium))
                        .foregroundColor(.gray)

                    Spacer()

                    Text("$59.00")
                        .font(.app(size: .lg, weight: .bold))
                        .foregroundColor(.gray)
                }.padding(.bottom, 8)
                
                
                HStack {
                    Text("Admin Fee")
                        .font(.app(size: .lg))
                        .foregroundColor(.gray)

                    Spacer()

                    Text("$01.00")
                        .font(.app(size: .lg, weight: .bold))
                        .foregroundColor(.gray)
                }.padding(.bottom, 8)
                
                HStack {
                    Text("Additional Discount")
                        .font(.app(size: .lg, weight: .medium))
                        .foregroundColor(.gray)

                    Spacer()

                    Text("_")
                        .font(.app(size: .lg, weight: .bold))
                        .foregroundColor(.gray)
                }.padding(.bottom, 8)
                
                
                HStack {
                    Text("Total")
                        .font(.app(size: .lg, weight: .bold))
                        .foregroundColor(.gray)

                    Spacer()

                    Text("$70.00")
                        .font(.app(size: .lg, weight: .bold))
                        .foregroundColor(.gray)
                }.padding(.bottom, 8)
                
            } .padding(.top, 20)
            
            
            
        }
        
        
    }
    
}


